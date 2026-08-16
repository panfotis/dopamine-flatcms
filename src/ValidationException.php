<?php

declare(strict_types=1);

namespace Dopamine\FlatCms;

use RuntimeException;

/**
 * Thrown when a value the client typed is refused — today only "required and
 * empty", which is the whole of Fields::demand().
 *
 * It carries the *path* to the field as well as the message, because an error
 * page that says "the description is missing" and throws the form away is the
 * same support call as a lost save. The path is assembled as the exception
 * unwinds: demand() names the field, Fields::map() prepends its own key at each
 * depth and items() prepends the row index, so an alt inside a list row arrives
 * as ['gallery', '2', 'image', 'alt'] without anything having to track a path
 * on the way *down*.
 *
 * field() then renders it as the form's `name` attribute — the one string the
 * panel already builds for every input — so the template can look the error up
 * beside the box that caused it.
 */
final class ValidationException extends RuntimeException
{
    /** @param list<string> $path */
    public function __construct(string $message, private readonly array $path = [])
    {
        parent::__construct($message);
    }

    /** The same error, one level deeper in the form. */
    public function under(string $segment): self
    {
        return new self($this->getMessage(), [$segment, ...$this->path]);
    }

    /** e.g. field('blocks[intro]') -> "blocks[intro][image][alt]" */
    public function field(string $prefix): string
    {
        return array_reduce(
            $this->path,
            static fn (string $carry, string $segment): string => $carry . '[' . $segment . ']',
            $prefix
        );
    }
}
