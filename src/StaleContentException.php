<?php

declare(strict_types=1);

namespace Dopamine\FlatCms;

use RuntimeException;

/**
 * Thrown when a save arrives carrying a baseline that no longer matches the
 * file on disk — someone else saved in the meantime.
 *
 * Distinct from a generic error because the response is different: the client's
 * submitted values must be handed straight back to them in a re-rendered form,
 * never discarded. Refusing the write is the correct behaviour; losing what they
 * typed is not, and is how "safe concurrency" turns into a support call.
 */
final class StaleContentException extends RuntimeException
{
}
