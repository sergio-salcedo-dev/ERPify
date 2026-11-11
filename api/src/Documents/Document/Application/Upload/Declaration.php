<?php

declare(strict_types=1);

namespace Erpify\Documents\Document\Application\Upload;

use DateTimeImmutable;
use Erpify\Documents\Document\Domain\Upload\LegalBasis;
use Erpify\Documents\Document\Domain\Upload\RetentionReason;
use LogicException;

/**
 * One declare's parameters, normalised once so the replay comparison and the insert read the same values.
 */
final readonly class Declaration
{
    public function __construct(
        public string $actorId,
        public int $size,
        public ?string $sha256,
        public ?string $keyHash,
        public RetentionReason $retentionReason,
        public LegalBasis $legalBasis,
        public ?DateTimeImmutable $reviewAt,
    ) {
        if (null !== $keyHash && null === $sha256) {
            throw new LogicException('An idempotency key requires a declared sha256; the edge validates it.');
        }
    }
}
