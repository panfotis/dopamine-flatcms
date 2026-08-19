<?php
/**
 * The contact form (plan §7).
 *
 * The pipeline order is the security property, and most of what is checked here
 * is an ordering claim rather than a feature: rate limit before anything can
 * cost an outbound call, disk before the wire, and a delivery failure that
 * costs the client nothing because the record already exists.
 */

declare(strict_types=1);

session_start();

require __DIR__ . '/lib.php';

use Dopamine\FlatCms\Cms;
use Dopamine\FlatCms\Form;
use Dopamine\FlatCms\Submissions;
use Symfony\Component\HttpFoundation\Request;

putenv('AUTH_DEV_BYPASS=1');

$root = dirname(__DIR__);

/** A Cms whose submissions and rate-limit counters live in a throwaway directory. */
$sandbox = $root . '/var/cache/form-' . bin2hex(random_bytes(4));
register_shutdown_function(static function () use ($sandbox): void {
    foreach (glob($sandbox . '/submissions/*/*.json') ?: [] as $f) {
        @unlink($f);
    }
    foreach (glob($sandbox . '/submissions/*', GLOB_ONLYDIR) ?: [] as $d) {
        @rmdir($d);
    }
    foreach (glob($sandbox . '/cache/ratelimit/*.json') ?: [] as $f) {
        @unlink($f);
    }
    @rmdir($sandbox . '/submissions');
    @rmdir($sandbox . '/cache/ratelimit');
    @rmdir($sandbox . '/cache');
    @rmdir($sandbox);
});

$make = static function (array $overrides = []) use ($root, $sandbox): Cms {
    $config = require $root . '/config.php';
    $config['paths']['cache'] = $sandbox . '/cache';
    $config['form'] = $overrides + $config['form'];

    return new Cms($config);
};

// The rate limiter is the *first* step of the pipeline and would otherwise
// refuse the later sections here; it gets its own Cms and its own addresses
// below, so every other case exercises what it says it does.
$cms = $make(['rate_limit' => 1000]);
$page = $cms->content->load('epikoinonia');
$form = new Form($cms);

section('form_guards() emits the two inputs handle() validates by name');

$rendered = $cms->renderPage($page, ['form_csrf' => 'form-token', 'form_inputs' => $form->inputs(
    $cms->components->get('contact_form') ?? []
), 'form_errors' => [], 'form_values' => [], 'form_sent' => false, 'turnstile_site_key' => '']);
contains($rendered, '<input type="hidden" name="csrf" value="form-token">', 'the CSRF token, from the request context');
contains($rendered, 'name="website" tabindex="-1" autocomplete="off"',
    'and the honeypot, out of the tab order and away from password managers');
contains($rendered, 'left:-9999px', 'hidden off-screen with inline style — it survives a form whose author replaced every stylesheet');
missing($rendered, 'display:none', 'never display:none — a bot that skips obviously hidden inputs is the bot the trap is for');
ok(substr_count($rendered, 'name="website"') === 1, 'exactly one honeypot');

/** A POST that would succeed, unless a case below breaks it on purpose. */
$post = static function (array $body = [], array $server = []): Request {
    // $body first: `+` keeps the left operand, so an override has to be there.
    return Request::create('/epikoinonia', 'POST', $body + [
        'csrf'    => 'form-token',
        'name'    => 'Φώτης',
        'email'   => 'fotis@example.gr',
        'phone'   => '2100000000',
        'message' => 'Καλησπέρα, θα ήθελα μια προσφορά.',
        'consent' => '1',
    ], [], [], $server + ['REMOTE_ADDR' => '198.51.100.7']);
};

/** Put the session in the state a rendered form leaves it in. */
$opened = static function (int $secondsAgo = 30): void {
    $_SESSION['form_csrf'] = 'form-token';
    $_SESSION['form_opened_at'] = time() - $secondsAgo;
};

section('The form is developer-defined and client-worded');
$schema = $cms->components->get('contact_form');
ok(array_keys($form->inputs($schema)) === ['name', 'email', 'phone', 'message'],
    'the visitor inputs come from the component `form:` key');
ok($form->inputs($schema)['email']['type'] === 'email', 'with their declared types');
ok($form->inputs($schema)['message']['required'] === true, 'and their declared requiredness');
// The one rule that matters: `form:` is not a `fields:` entry and never becomes
// one — a client who could edit it could add an input.
ok(!array_key_exists('name', $schema['fields']), 'an input is not a panel field');
ok(array_key_exists('heading', $schema['fields']), 'while the words around it are');
ok($schema['fields']['recipient']['editable'] === 'admin', 'the recipient is admin-only');
ok($schema['fields']['turnstile']['editable'] === 'admin', 'and so is the Turnstile toggle');

section('A valid submission lands on disk before anything is sent');
$opened();
$result = $form->handle($post(), $page);
ok($result['ok'] === true, 'the submission is accepted');

$stored = $cms->submissions->all();
ok(count($stored) === 1, 'exactly one record was written');
ok($stored[0]['values']['message'] === 'Καλησπέρα, θα ήθελα μια προσφορά.', 'with what the visitor typed');
ok($stored[0]['page'] === 'epikoinonia' && $stored[0]['locale'] === 'el', 'and where it came from');
// Mail is not configured on this box, which is exactly the case that must not
// lose a lead: the record exists and is marked, not discarded.
ok($stored[0]['status'] === Submissions::UNSENT, 'a delivery failure leaves it unsent rather than gone');
ok($stored[0]['attempts'] === 1, 'with the attempt counted');

section('A submission file carries no raw IP');
// It is the copy that gets backed up off-host and kept for a year. The rate
// limiter needs the address for ten minutes; nothing needs it for twelve months.
ok(!str_contains(json_encode($stored[0], JSON_THROW_ON_ERROR), '198.51.100.7'),
    'the address itself is nowhere in the record');
ok($stored[0]['ip_hash'] === hash('sha256', '198.51.100.7'), 'only its hash, which cannot be reversed to a visitor');
ok(preg_match('/^[0-9a-f]{16}$/', (string) $stored[0]['id']) === 1,
    'and the id is random, never derived from anything the visitor sent');

section('Validation refuses what it should, and keeps what was typed');
$opened();
$bad = $form->handle($post(['name' => '', 'email' => 'not-an-address', 'message' => '']), $page);
ok($bad['ok'] === false, 'a submission missing required fields is refused');
ok(isset($bad['errors']['name'], $bad['errors']['message']), 'with a message per empty required field');
ok(isset($bad['errors']['email']), 'and one for the address that is not one');
ok($bad['values']['phone'] === '2100000000', 'while what was typed comes back rather than being lost');
ok(count($cms->submissions->all()) === 1, 'and nothing was stored');

$opened();
$noConsent = $form->handle($post(['consent' => '']), $page);
ok($noConsent['ok'] === false && isset($noConsent['errors']['consent']),
    'the GDPR consent box is refused when the component asks for one');

section('Values are plain text by the time they are stored');
$opened();
$form->handle($post(['message' => 'Γεια<script>alert(1)</script><b>σας</b>']), $page);
$latest = $cms->submissions->all()[0];
missing($latest['values']['message'], '<script', 'a script tag never reaches the record');
missing($latest['values']['message'], '<b>', 'nor any other markup — this lands in an email and on an admin screen');

section('The honeypot and the clock drop silently');
// Silent on purpose: a bot told which check it failed is a bot that passes next
// time. The visitor sees the ordinary success page and nothing is stored.
$before = count($cms->submissions->all());

$opened();
$trap = $form->handle($post(['website' => 'http://spam.example']), $page);
ok($trap['ok'] === true, 'a filled honeypot looks exactly like success');
ok(count($cms->submissions->all()) === $before, 'and stores nothing');

$opened(0);   // submitted the instant the page rendered
$fast = $form->handle($post(), $page);
ok($fast['ok'] === true, 'so does a submission that arrived faster than a person can type');
ok(count($cms->submissions->all()) === $before, 'and it stores nothing either');

$_SESSION['form_csrf'] = 'form-token';
unset($_SESSION['form_opened_at']);
$noSession = $form->handle($post(), $page);
ok(count($cms->submissions->all()) === $before, 'a POST with no rendered form behind it stores nothing');

section('CSRF is enforced');
$opened();
$forged = $form->handle($post(['csrf' => 'wrong']), $page);
ok($forged['ok'] === false, 'a wrong token is refused');
ok(count($cms->submissions->all()) === $before, 'and stores nothing');

section('The rate limit runs first, and keys off the visitor');
// First is the point: a POST flood must not force one outbound HTTPS call per
// worker before anything refuses it.
$limited = $make(['rate_limit' => 3, 'rate_window' => 600]);
$spammer = ['REMOTE_ADDR' => '198.51.100.99'];
$limitedForm = new Form($limited);
$limitedPage = $limited->content->load('epikoinonia');

$accepted = 0;
for ($i = 0; $i < 6; $i++) {
    $opened();
    if ($limitedForm->handle($post(['message' => 'Μήνυμα ' . $i], $spammer), $limitedPage)['ok']) {
        $accepted++;
    }
}
ok($accepted === 3, 'the fourth submission from one address is refused: ' . $accepted . ' accepted');

// A different visitor is a different bucket, or one spammer takes the form down
// for everybody.
$opened();
ok($limitedForm->handle($post([], ['REMOTE_ADDR' => '203.0.113.9']), $limitedPage)['ok'] === true,
    'while a different address still gets through');

// And the counter is keyed on the hash, so var/ is not a visitor list.
$counters = array_map('basename', glob($sandbox . '/cache/ratelimit/*.json') ?: []);
ok($counters !== [], 'the counters exist');
ok(!in_array('198.51.100.7.json', $counters, true), 'and none of them is named after an address');
ok(in_array(hash('sha256', '198.51.100.7') . '.json', $counters, true), 'they are named after its hash');

section('The recipient is one valid address, or none');
$deliverable = new ReflectionMethod(Form::class, 'recipient');
$deliverable->setAccessible(true);
$to = static fn (string $value): string => $deliverable->invoke($form, ['recipient' => $value]);

ok($to('lead@pelatis.gr') === 'lead@pelatis.gr', 'one valid address is used');
// As client-editable text this is a lead redirect; as a comma-separated list it
// is a lead redirect with a copy left behind so nobody notices.
ok($to('lead@pelatis.gr, attacker@evil.gr') === '', 'a second address smuggled in with a comma is refused entirely');
ok($to('not-an-address') === '', 'and so is anything that is not one');
ok($to('') === '', 'empty falls through to the site default, which is empty on this box');

section('An ambiguous failure is flagged for review, never retried blindly');
$classify = new ReflectionMethod(Form::class, 'mailFailure');
$classify->setAccessible(true);

ok($classify->invoke(null, 'Connection could not be established: timed out')['status'] === Submissions::REVIEW,
    'a timeout may already have been delivered, so it waits for a person');
ok($classify->invoke(null, 'connection reset by peer')['status'] === Submissions::REVIEW, 'so does a reset mid-session');
ok($classify->invoke(null, 'Expected response code 250 but got 550 mailbox unavailable')['status'] === Submissions::UNSENT,
    'while a clean refusal is safe to try again');

section('Storage: one file per submission, atomic, and erasable');
$subs = $cms->submissions;
$one = $subs->store('epikoinonia', 'el', ['name' => 'Α'], 'hash');
$two = $subs->store('epikoinonia', 'el', ['name' => 'Β'], 'hash');
ok($one['id'] !== $two['id'], 'two submissions never share an id');
ok($subs->get($one['month'], $one['id'])['values']['name'] === 'Α', 'a record reads back by id');

$reviewed = $subs->store('epikoinonia', 'el', ['name' => 'Γ'], 'hash');
$subs->update($reviewed['month'], $reviewed['id'], ['status' => Submissions::REVIEW, 'error' => 'timeout']);
ok($form->deliver($reviewed, []) === false, 'a review record is refused even when a retry is forged server-side');
ok($subs->get($reviewed['month'], $reviewed['id'])['status'] === Submissions::REVIEW,
    'and remains awaiting review rather than being sent or downgraded to unsent');

$alreadySent = $subs->store('epikoinonia', 'el', ['name' => 'Δ'], 'hash');
$subs->update($alreadySent['month'], $alreadySent['id'], ['status' => Submissions::SENT]);
ok($form->deliver($alreadySent, []) === false, 'an already-sent record cannot be delivered twice through a stale action');

// Deleting one and updating another cannot race: they are different files.
$subs->update($two['month'], $two['id'], ['status' => Submissions::SENT]);
ok($subs->delete($one['month'], $one['id']) === true, 'one record is deleted');
ok($subs->get($one['month'], $one['id']) === null, 'and is gone');
ok($subs->get($two['month'], $two['id'])['status'] === Submissions::SENT, 'while the other kept the update');
ok($subs->get($two['month'], $two['id'])['values']['name'] === 'Β', 'and the rest of its content');

// Ids and months arrive from the request.
$refused = static function (callable $fn): bool {
    try {
        $fn();

        return false;
    } catch (\RuntimeException) {
        return true;
    }
};
ok($refused(static fn () => $subs->get('../../etc', 'passwd')), 'a crafted month is refused');
ok($refused(static fn () => $subs->get('2026-08', '../../../config')), 'and so is a crafted id');
ok($refused(static fn () => $subs->delete('2026-08', 'not-an-id')), 'on the delete path too');

section('Retention deletes whole months past the window');
$old = $sandbox . '/submissions/' . date('Y-m', strtotime('-18 months'));
mkdir($old, 0770, true);
file_put_contents($old . '/' . str_repeat('a', 16) . '.json', json_encode(['id' => str_repeat('a', 16), 'at' => 'x']));
ok(count($subs->prune(12)) === 1, 'a month older than the window is removed');
ok(!is_dir($old), 'directory and all');
ok($subs->get($two['month'], $two['id']) !== null, 'while this month is untouched');

section('Only an admin may read or erase a submission');
// Visitor PII, so the same gate as revisions — and enforced, not merely hidden.
[$accessConfig, $sign] = access();
$asRole = static function (string $email, string $action, array $params = [], string $method = 'GET') use ($sign, $accessConfig): int {
    return admin(
        Request::create('/admin.php', $method, ['action' => $action] + $params, [], [], [
            'HTTP_CF_ACCESS_JWT_ASSERTION' => $sign($email),
        ]),
        $accessConfig
    )->getStatusCode();
};

ok($asRole('pelatis@example.gr', 'submissions') === 403, 'an editor cannot list them');
ok($asRole('fotis@wearedope.com', 'submissions') === 200, 'an admin can');
ok($asRole('pelatis@example.gr', 'submission_delete', ['month' => '2026-08', 'id' => str_repeat('a', 16)], 'POST') === 403,
    'an editor forging a delete is refused before CSRF is even considered');
ok($asRole('pelatis@example.gr', 'submission_retry', ['month' => '2026-08', 'id' => str_repeat('a', 16)], 'POST') === 403,
    'and so is a forged retry');

section('Choice inputs: select, radio, checkbox');
// These never reach the browser's honesty: a POST can claim any value, so a
// select and a radio are whitelisted against their declared options, and a
// checkbox stores exactly '1' or ''.
$choices = $form->inputs(['form' => [
    'size'  => ['type' => 'select', 'options' => ['' => '—', 's' => 'Small', 'm' => 'Medium']],
    'via'   => ['type' => 'radio', 'required' => true, 'options' => ['tel' => 'Phone', 'email' => 'Email']],
    'news'  => ['type' => 'checkbox'],
    'weird' => ['type' => 'file'],
]]);
ok($choices['size']['type'] === 'select' && $choices['size']['options'] === ['' => '—', 's' => 'Small', 'm' => 'Medium'],
    'a select keeps its options, normalised to a map');
ok($choices['via']['type'] === 'radio' && $choices['news']['type'] === 'checkbox',
    'radio and checkbox are accepted types');
ok($choices['weird']['type'] === 'text', 'an undeclared type still downgrades to text');

$validate = new ReflectionMethod(Form::class, 'validate');
$try = static fn (array $body): array => $validate->invoke(
    $form,
    Request::create('/epikoinonia', 'POST', $body),
    $choices
);

[$values, $errors] = $try(['size' => 'm', 'via' => 'tel', 'news' => '1']);
ok($errors === [] && $values['size'] === 'm' && $values['via'] === 'tel' && $values['news'] === '1',
    'declared values pass through');

[$values, $errors] = $try(['size' => 'xxl; DROP', 'via' => 'carrier-pigeon']);
ok($values['size'] === '', 'a forged select value falls back to the first (placeholder) option');
ok(isset($errors['via']), 'a forged radio value is empty, so a required group refuses');
ok($values['news'] === '', 'an unchecked checkbox stores the empty string, not an absence');

[, $errors] = $try(['size' => 's']);
ok(isset($errors['via']), 'a missing required radio refuses too');

summary();
