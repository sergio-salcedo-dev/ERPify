<?php

declare(strict_types=1);

namespace Erpify\Shared\ErrorContract\Domain\Exception;

/**
 * Marker for a request whose body is only acceptable with a declared length and arrived without one: 411. Raised
 * before any byte of the body is read, by an endpoint that cannot tell a body cut short in transit from a
 * complete shorter one unless the length was declared up front.
 */
interface LengthRequired extends ClientError
{
}
