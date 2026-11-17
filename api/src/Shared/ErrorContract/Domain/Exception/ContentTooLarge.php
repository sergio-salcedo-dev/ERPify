<?php

declare(strict_types=1);

namespace Erpify\Shared\ErrorContract\Domain\Exception;

/**
 * Marker for a request whose body exceeds a bound the endpoint declares: 413. That is the byte cap, known before the
 * body is decoded, and the limits on what the body decodes to (pixels, dimensions); a body that is merely malformed is
 * an {@see InvariantViolation}.
 */
interface ContentTooLarge extends ClientError
{
}
