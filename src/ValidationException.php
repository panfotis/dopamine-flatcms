<?php

declare(strict_types=1);

namespace Dopamine\FlatCms;

use RuntimeException;

/**
 * Thrown when a value the client typed is refused — today only "required and
 * empty", which is the whole of Fields::demand().
 *
 * It carries the *path* to each refused field as well as its message, because
 * an error page that says "the description is missing" and throws the form
 * away is the same support call as a lost save. A path is assembled as the
 * exception unwinds: demand() names the field, Fields::map() prepends its own
 * key at each depth and items() prepends the row index, so an alt inside a
 * list row arrives as ['gallery', '2', 'image', 'alt'] without anything having
 * to track a path on the way *down*.
 *
 * One exception can carry several refusals: map() keeps walking after a
 * refused field and merge()s what it collected, so the panel marks every
 * empty required box in one round trip instead of one per save. fields() then
 * renders each path as the form's `name` attribute — the one string the panel
 * already builds for every input — so the template can look each error up
 * beside the box that caused it.
 */
final class ValidationException extends RuntimeException
{
    /** @var list<array{path: list<string>, message: string}> */
    private array $errors;

    /** @param list<string> $path */
    public function __construct(string $message, array $path = [])
    {
        parent::__construct($message);
        $this->errors = [['path' => $path, 'message' => $message]];
    }

    /**
     * Everything a walk refused, as one exception. The message is the first
     * refusal's — for anything that shows a single line, first is the one the
     * client reads top-of-form anyway.
     *
     * @param non-empty-list<self> $refusals
     */
    public static function merge(array $refusals): self
    {
        $out = new self($refusals[0]->getMessage());
        $out->errors = array_merge(...array_map(
            static fn (self $e): array => $e->errors,
            $refusals
        ));

        return $out;
    }

    /** The same errors, one level deeper in the form. */
    public function under(string $segment): self
    {
        $out = new self($this->getMessage());
        $out->errors = array_map(
            static fn (array $e): array => ['path' => [$segment, ...$e['path']], 'message' => $e['message']],
            $this->errors
        );

        return $out;
    }

    /**
     * e.g. fields('blocks[intro]') -> ['blocks[intro][image][alt]' => '…']
     *
     * @return array<string, string>
     */
    public function fields(string $prefix): array
    {
        $out = [];
        foreach ($this->errors as $e) {
            $name = array_reduce(
                $e['path'],
                static fn (string $carry, string $segment): string => $carry . '[' . $segment . ']',
                $prefix
            );
            $out[$name] = $e['message'];
        }

        return $out;
    }
}
