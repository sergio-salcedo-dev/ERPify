<?php

declare(strict_types=1);

namespace Erpify\Shared\ErrorContract\Domain\Exception;

/**
 * Marker for a body whose format the endpoint does not accept: 415. It is a domain-side refusal, raised after the
 * content was recognised. A concrete exception may carry its own `type`; the framework's own 415 for a request payload
 * of the wrong content type is a separate source and keeps the `unsupported-media-type` default.
 */
interface UnsupportedMediaType extends ClientError
{
}
