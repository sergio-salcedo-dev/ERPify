<?php

declare(strict_types=1);

namespace Erpify\Documents\Document\Infrastructure\Http;

use Erpify\Documents\Document\Domain\Erasure\ErasureReason;
use Erpify\Iam\Identity\Infrastructure\Http\ExistingCredential;
use SensitiveParameter;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * Input payload of the operator erasure. The reason is the HTTP type {@see ErasureReason}, which holds neither system
 * reason, so `#[StrictRequestPayload]` answers `ledger-replay` or any other value with a 422 `validation-failed` at
 * the boundary; a body that omits it fails the same way, since the member has no default.
 *
 * `currentPassword` is the caller's own, re-proved before anything is written: the erasure cannot be undone even from a
 * backup, so a stolen session alone must not be enough to perform it. It is never echoed, logged or stored.
 */
final readonly class EraseDocumentRequest
{
    public function __construct(
        public ErasureReason $reason,
        #[Assert\NotBlank]
        #[Assert\Length(max: ExistingCredential::LENGTH_CEILING)]
        #[SensitiveParameter]
        public string $currentPassword = '',
    ) {
    }
}
