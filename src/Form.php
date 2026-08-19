<?php

declare(strict_types=1);

namespace Dopamine\FlatCms;

use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Mailer\Mailer;
use Symfony\Component\Mailer\Transport;
use Symfony\Component\Mime\Email;
use Throwable;

/**
 * The contact form: one POST, in one order.
 *
 *   1. rate limit          — first, always
 *   2. honeypot + minimum time-to-submit
 *   3. validation
 *   4. Turnstile, if an admin switched it on
 *   5. write to disk
 *   6. send
 *
 * **The order is the security property.** Rate limiting first is what stops a
 * POST flood from forcing one outbound HTTPS call per worker before anything
 * refuses it — a captcha check that runs before the counter is a denial of
 * service with extra steps. Everything after step 1 is cheap by comparison.
 *
 * Steps 1–3 are the always-on baseline and about forty lines. On a brochure
 * site they stop effectively all drive-by bot traffic, which is why Turnstile
 * is off by default rather than assumed.
 *
 * The visitor-facing inputs are **developer-defined**, in the component's
 * `form:` key. The client edits the words around them — heading, intro, success
 * message, consent text — and nothing else. That is the same rule as everywhere
 * else here: structure in files, content in the panel.
 */
final class Form
{
    /** What a `form:` input may declare as its type. */
    private const TYPES = ['text', 'email', 'tel', 'textarea', 'select', 'radio', 'checkbox'];

    public function __construct(private readonly Cms $cms)
    {
    }

    /**
     * Handle a POST against a page that carries a form block.
     *
     * Returns `['ok' => bool, 'errors' => [...], 'block' => id]` — never a
     * Response, because the entry point owns the redirect and the 422, and a
     * method that both validated and redirected could not be tested in-process.
     *
     * @param  array<string, mixed> $page
     * @return array{ok: bool, errors: array<string, string>, block: string, values: array<string, string>}
     */
    public function handle(Request $request, array $page): array
    {
        // The language of the page the visitor is standing on, not the
        // panel's: a Greek page must refuse in Greek even when the person
        // editing the site reads the panel in English.
        $say = fn (string $k, string|int ...$a): string => $this->cms->siteLang()->t($k, ...$a);

        $block = $this->blockOn($page);
        $fail = static fn (string $message, string $field = '_'): array
            => ['ok' => false, 'errors' => [$field => $message], 'block' => (string) ($block['id'] ?? ''), 'values' => []];

        if ($block === null) {
            return $fail($say('form.no_form'));
        }

        $schema = $this->cms->components->get((string) $block['type']);
        $inputs = $this->inputs($schema ?? []);
        $fields = $this->cms->withDefaults($schema ?? ['fields' => []], $block['fields']);

        // ── 1. Rate limit ───────────────────────────────────────────────────
        // Before the CSRF check even: a flood of tokenless POSTs still costs a
        // session start and a hash per request, and this is the cheapest
        // possible refusal.
        if (!$this->underLimit($this->ipHash($request))) {
            return $fail($say('form.rate_limited'));
        }

        if (!hash_equals((string) ($_SESSION['form_csrf'] ?? ''), (string) $request->request->get('csrf', ''))) {
            return $fail($say('form.expired'));
        }

        // ── 2. Honeypot + minimum time-to-submit ────────────────────────────
        // Both are silent on purpose: a bot that is told which check it failed
        // is a bot that passes next time. The visitor sees the ordinary success
        // page, and nothing is stored or sent.
        $opened = (int) ($_SESSION['form_opened_at'] ?? 0);
        $tooFast = $opened === 0 || (time() - $opened) < (int) $this->cms->config['form']['min_seconds'];

        if ((string) $request->request->get('website', '') !== '' || $tooFast) {
            error_log('[dopamine-flatcms] form: silent drop (' . ($tooFast ? 'too fast' : 'honeypot') . ')');

            return ['ok' => true, 'errors' => [], 'block' => (string) $block['id'], 'values' => []];
        }

        // ── 3. Validation ───────────────────────────────────────────────────
        // filter_var and Fields, not symfony/validator: a second validation
        // system beside Fields is the thing this project has said no to since
        // §2, and an email address is one function call.
        [$values, $errors] = $this->validate($request, $inputs);

        if (($fields['consent_text'] ?? '') !== '' && (string) $request->request->get('consent', '') === '') {
            $errors['consent'] = $say('form.consent_required');
        }

        if ($errors !== []) {
            return ['ok' => false, 'errors' => $errors, 'block' => (string) $block['id'], 'values' => $values];
        }

        // ── 4. Turnstile, only if an admin switched it on ───────────────────
        if (($fields['turnstile'] ?? false) === true && !$this->turnstile($request)) {
            return ['ok' => false, 'errors' => ['_' => $say('form.challenge_failed')],
                    'block' => (string) $block['id'], 'values' => $values];
        }

        // ── 5. Disk, before the wire ────────────────────────────────────────
        // The record exists before anything can fail to deliver it. That
        // ordering is the whole reason a mail outage costs the client nothing.
        $record = $this->cms->submissions->store(
            (string) ($page['id'] ?? ''),
            $this->cms->locale(),
            $values,
            $this->ipHash($request)
        );

        // ── 6. Send ─────────────────────────────────────────────────────────
        $this->deliver($record, $fields);

        return ['ok' => true, 'errors' => [], 'block' => (string) $block['id'], 'values' => []];
    }

    /**
     * The first block on the page whose component declares a `form:`.
     *
     * Off the schema rather than off a component name, so a site can call its
     * form component whatever it likes and a page can carry one anywhere.
     *
     * @param  array<string, mixed> $page
     * @return array<string, mixed>|null
     */
    public function blockOn(array $page): ?array
    {
        foreach ((array) ($page['blocks'] ?? []) as $block) {
            $schema = $this->cms->components->get((string) ($block['type'] ?? ''));
            if ($schema !== null && $this->inputs($schema) !== []) {
                return $block;
            }
        }

        return null;
    }

    /**
     * The visitor-facing inputs a component declares, normalised.
     *
     * Deliberately *not* run through Components::normalise(): these are not
     * panel fields and must never become any. A `form:` entry the client could
     * edit would let them add an input, and an input is where a value comes
     * from — which is the one thing structure-in-files exists to prevent.
     *
     * @param  array<string, mixed> $schema
     * @return array<string, array{label: string, type: string, required: bool, max: int, options: array<string, string>}>
     */
    public function inputs(array $schema): array
    {
        $out = [];
        foreach ((array) ($schema['form'] ?? []) as $name => $def) {
            if (preg_match('/^[a-z0-9_]+$/i', (string) $name) !== 1) {
                continue; // a name is an input attribute; it is not free text
            }

            $def = is_array($def) ? $def : [];
            $type = (string) ($def['type'] ?? 'text');

            $out[(string) $name] = [
                'label'    => (string) ($def['label'] ?? $name),
                'type'     => in_array($type, self::TYPES, true) ? $type : 'text',
                'required' => ($def['required'] ?? false) === true,
                'max'      => max(1, (int) ($def['max'] ?? 500)),
                // Empty for every other type, and harmless there. A select
                // whose first option has an empty key is the placeholder, and
                // is what makes `required` mean anything on a dropdown.
                'options'  => Fields::options($def),
            ];
        }

        return $out;
    }

    /**
     * @param  array<string, array<string, mixed>> $inputs
     * @return array{0: array<string, string>, 1: array<string, string>}
     */
    private function validate(Request $request, array $inputs): array
    {
        $values = [];
        $errors = [];

        foreach ($inputs as $name => $def) {
            // Every value is plain text by the time it is stored, whatever the
            // input said it was: this lands in an email, in a JSON file and on
            // an admin screen, and none of those wants markup from a stranger.
            $raw = $request->request->get($name);
            $raw = is_scalar($raw) ? (string) $raw : '';

            $value = match ($def['type']) {
                // Whitelisted against its own options: the browser only offers
                // what we declared, a POST can say anything. A select falls
                // back to its first option — declare an empty-keyed placeholder
                // first so `required` means something — while a radio stays
                // empty on a miss, because nothing on screen was checked.
                'select'   => Fields::sanitise(['type' => 'select', 'options' => $def['options']], $raw),
                'radio'    => array_key_exists($raw, $def['options']) ? $raw : '',
                // One yes/no box, same shape as consent: checked stores '1'.
                'checkbox' => $raw !== '' ? '1' : '',
                default    => Fields::sanitise(
                    ['type' => $def['type'] === 'textarea' ? 'textarea' : 'text', 'max' => $def['max']],
                    $raw
                ),
            };

            if ($def['required'] && $value === '') {
                $errors[$name] = $this->cms->siteLang()->t(
                    'field.required',
                    $this->cms->siteLang()->t($def['label'])
                );
                continue;
            }

            if ($def['type'] === 'email' && $value !== '' && filter_var($value, FILTER_VALIDATE_EMAIL) === false) {
                $errors[$name] = $this->cms->siteLang()->t('form.bad_email');
                continue;
            }

            $values[$name] = (string) $value;
        }

        return [$values, $errors];
    }

    /**
     * A per-IP counter in a file, keyed on the **hash** of the address.
     *
     * Never the raw value: a directory listing of var/ratelimit/ would
     * otherwise be a list of everyone who visited the contact page, kept
     * beside a form whose whole point is that visitor data is handled
     * carefully. The counter expires on its own window and is not a record.
     */
    private function underLimit(string $ipHash): bool
    {
        $dir = $this->cms->config['paths']['cache'] . '/ratelimit';
        if (!is_dir($dir) && !@mkdir($dir, 0770, true) && !is_dir($dir)) {
            return true; // never refuse a real submission over a disk problem
        }

        $window = max(1, (int) $this->cms->config['form']['rate_window']);
        $limit = max(1, (int) $this->cms->config['form']['rate_limit']);
        $file = $dir . '/' . $ipHash . '.json';

        $handle = @fopen($file, 'c+');
        if ($handle === false || !flock($handle, LOCK_EX)) {
            if (is_resource($handle)) {
                fclose($handle);
            }

            return true; // disk trouble must not silently eat a real lead
        }

        try {
            rewind($handle);
            $state = json_decode((string) stream_get_contents($handle), true);
            $state = is_array($state) ? $state : [];

            $now = time();
            $since = (int) ($state['since'] ?? 0);
            $count = (int) ($state['count'] ?? 0);

            if ($now - $since >= $window) {
                $since = $now;
                $count = 0;
            }

            rewind($handle);
            ftruncate($handle, 0);
            fwrite($handle, json_encode(
                ['since' => $since, 'count' => $count + 1],
                JSON_THROW_ON_ERROR
            ));
            fflush($handle);

            return $count < $limit;
        } finally {
            flock($handle, LOCK_UN);
            fclose($handle);
        }
    }

    /**
     * The visitor's address, hashed.
     *
     * REMOTE_ADDR by default, which is correct when nginx has
     * `set_real_ip_from` + `real_ip_header CF-Connecting-IP` with the
     * Cloudflare ranges — the arrangement nginx.conf.example ships, and the one
     * that cannot be forged because nginx checks who it is talking to.
     *
     * `trust_cf_ip` is the fallback for an origin that cannot do that, and it
     * is off by default: trusting a header on a directly reachable origin lets
     * anyone mint their own rate-limit bucket by changing one request header.
     */
    private function ipHash(Request $request): string
    {
        $ip = (string) $request->server->get('REMOTE_ADDR', '');

        if (($this->cms->config['form']['trust_cf_ip'] ?? false) === true) {
            $ip = (string) ($request->headers->get('CF-Connecting-IP') ?: $ip);
        }

        return hash('sha256', $ip);
    }

    /**
     * Turnstile, **fail open**.
     *
     * If siteverify is unreachable the submission is accepted. The rate limit,
     * the honeypot and the minimum time-to-submit have all already run, so a
     * Cloudflare outage degrades one layer of four rather than silently eating
     * a client's leads for an afternoon — and losing a lead is the worse
     * outcome, which is the same judgement the mail path makes below.
     *
     * Every fail-open is logged, so a sustained one is visible rather than
     * inferred from a quiet week.
     */
    private function turnstile(Request $request): bool
    {
        $secret = (string) $this->cms->config['turnstile']['secret'];
        if ($secret === '') {
            // Switched on with no keys configured behaves as off. Refusing to
            // accept submissions over our own configuration mistake would cost
            // the client leads; the panel says the toggle is inert.
            return true;
        }

        $handle = curl_init('https://challenges.cloudflare.com/turnstile/v0/siteverify');
        curl_setopt_array($handle, [
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => http_build_query([
                'secret'   => $secret,
                'response' => (string) $request->request->get('cf-turnstile-response', ''),
                'remoteip' => (string) $request->server->get('REMOTE_ADDR', ''),
            ]),
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 5,
            CURLOPT_FOLLOWLOCATION => false,
        ]);

        $body = curl_exec($handle);
        $status = (int) curl_getinfo($handle, CURLINFO_RESPONSE_CODE);
        curl_close($handle);

        if (!is_string($body) || $status !== 200) {
            error_log('[dopamine-flatcms] turnstile unreachable — accepting the submission (fail open)');

            return true;
        }

        return (json_decode($body, true)['success'] ?? false) === true;
    }

    /**
     * Try to mail one stored submission, and record what happened.
     *
     * Never throws. The record is already on disk, the visitor is already being
     * shown success, and a delivery failure is a thing to retry — not a reason
     * to tell someone their message was lost when it was not.
     *
     * A **timeout is not a failure**: the relay may well have accepted it, and
     * retrying an ambiguous send is how a client gets the same lead five times.
     * Those are flagged for an admin to look at instead.
     *
     * @param  array<string, mixed> $record
     * @param  array<string, mixed> $fields  the block's values, for the recipient
     * @return bool whether it went out
     */
    public function deliver(array $record, array $fields): bool
    {
        try {
            $result = $this->cms->submissions->transact(
                (string) $record['month'],
                (string) $record['id'],
                function (array $current) use ($fields): array {
                    $max = max(1, (int) $this->cms->config['form']['max_attempts']);
                    if (($current['status'] ?? '') !== Submissions::UNSENT
                        || (int) ($current['attempts'] ?? 0) >= $max) {
                        return [null, false];
                    }

                    $to = $this->recipient($fields);
                    $dsn = (string) $this->cms->config['form']['dsn'];
                    $from = (string) $this->cms->config['form']['from'];

                    if ($to === '' || $dsn === '' || $from === '') {
                        return [$this->failure($current, 'mail is not configured'), false];
                    }

                    try {
                        $mailer = new Mailer(Transport::fromDsn($dsn));
                        $mailer->send(
                            (new Email())
                                ->from($from)
                                ->to($to)
                                // The visitor's address goes here and never in
                                // From: a domain without SPF is what puts the
                                // whole site's mail in spam.
                                ->replyTo(...$this->replyTo($current))
                                ->subject($this->cms->siteLang()->t(
                                    'form.subject',
                                    (string) $this->cms->config['site']['name']
                                ))
                                ->text($this->body($current))
                        );
                    } catch (Throwable $e) {
                        return [$this->failure($current, $e->getMessage()), false];
                    }

                    return [['status' => Submissions::SENT, 'error' => ''], true];
                }
            );
        } catch (Throwable $e) {
            // The durable copy already exists. A status-write failure is an
            // operational error, not a reason to tell the visitor their words
            // were lost and invite a duplicate submission.
            error_log('[dopamine-flatcms] form: could not update delivery state — ' . $e->getMessage());

            return false;
        }

        return $result === true;
    }

    /**
     * Which state a failure leaves the record in.
     *
     * An ambiguous transport error — a timeout, a connection reset mid-session
     * — may well have been delivered, so it is flagged for review rather than
     * retried. Everything else is a clean "no", and safe to try again.
     *
     * @return array{status: string, error: string}
     */
    private static function mailFailure(string $message): array
    {
        $ambiguous = preg_match('/timed? ?out|timeout|connection reset|broken pipe/i', $message) === 1;

        return [
            'status' => $ambiguous ? Submissions::REVIEW : Submissions::UNSENT,
            'error'  => mb_substr($message, 0, 300),
        ];
    }

    /** @param array<string, mixed> $record */
    private function failure(array $record, string $message): array
    {
        $change = self::mailFailure($message);
        error_log('[dopamine-flatcms] form: delivery failed (' . $change['status'] . ') — ' . $change['error']);

        return $change + [
            'attempts' => (int) ($record['attempts'] ?? 0) + 1,
        ];
    }

    /**
     * The recipient: the component's, or the site default.
     *
     * `editable: admin` on the component and validated as **one** address. As
     * client-editable text it lets an editor redirect every lead off-site, or
     * fan out with `a@b.gr, attacker@x` — so a value that is not exactly one
     * valid address is not used at all.
     *
     * @param array<string, mixed> $fields
     */
    private function recipient(array $fields): string
    {
        foreach ([(string) ($fields['recipient'] ?? ''), (string) $this->cms->config['form']['to']] as $candidate) {
            if ($candidate !== '' && filter_var($candidate, FILTER_VALIDATE_EMAIL) !== false) {
                return $candidate;
            }
        }

        return '';
    }

    /**
     * Reply-To, from whichever input was declared an email — or nothing.
     *
     * @param  array<string, mixed> $record
     * @return list<string>
     */
    private function replyTo(array $record): array
    {
        foreach ((array) ($record['values'] ?? []) as $value) {
            if (is_string($value) && filter_var($value, FILTER_VALIDATE_EMAIL) !== false) {
                return [$value];
            }
        }

        return [];
    }

    /** @param array<string, mixed> $record */
    private function body(array $record): string
    {
        $lines = [];
        foreach ((array) ($record['values'] ?? []) as $name => $value) {
            $lines[] = $name . ': ' . $value;
        }

        $lines[] = '';
        $lang = $this->cms->siteLang();
        $lines[] = $lang->t('form.body_page') . ': ' . $record['page'] . ' (' . $record['locale'] . ')';
        $lines[] = $lang->t('form.body_date') . ': ' . $record['at'];
        $lines[] = $lang->t('form.body_reference') . ': ' . $record['id'];

        return implode("\n", $lines) . "\n";
    }
}
