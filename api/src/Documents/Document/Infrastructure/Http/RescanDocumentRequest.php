<?php

declare(strict_types=1);

namespace Erpify\Documents\Document\Infrastructure\Http;

use Erpify\Documents\Document\Domain\Scanning\RescanReason;

/**
 * Input payload of the operator re-scan. The reason is a closed enum, so `#[StrictRequestPayload]` answers a value
 * outside it with a 422 `validation-failed` at the boundary, before the use case is reached; a body that omits it
 * fails the same way, since the member has no default.
 */
final readonly class RescanDocumentRequest
{
    public function __construct(
        public RescanReason $reason,
    ) {
    }
}
