<?php

declare(strict_types=1);

namespace Dopamine\FlatCms;

use RuntimeException;

/**
 * Thrown when the request carries no identity we accept.
 *
 * Auth does not render, and it does not exit: an entrypoint that cannot be
 * unit-tested because it kills the process is how the auth path ends up being
 * the least-tested code in the panel. The caller turns this into a 403.
 */
final class AccessDeniedException extends RuntimeException
{
}
