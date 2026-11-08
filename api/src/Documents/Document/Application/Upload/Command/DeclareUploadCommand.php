<?php

declare(strict_types=1);

namespace Erpify\Documents\Document\Application\Upload\Command;

use DateTimeImmutable;
use DateTimeZone;
use Erpify\Documents\Document\Domain\Upload\LegalBasis;
use Erpify\Documents\Document\Domain\Upload\RetentionReason;
use Erpify\Documents\Document\Domain\UploadLimits;
use Symfony\Component\Validator\Constraints as Assert;
use Symfony\Component\Validator\Context\ExecutionContextInterface;

/**
 * Input of {@see \Erpify\Documents\Document\Application\Upload\DeclareUpload}: what the caller promises about the
 * original before sending a byte of it. There is no predecessor field: no code path files a successor version.
 *
 * The filename is not here and must never be: it travels in the PUT's query, is validated there and is sealed
 * in the commit transaction, so the intent row never holds it.
 *
 * `sha256` is mandatory whenever an idempotency key is supplied, so a replay is compared on the digest at the
 * declare step and never streams twice; it is compared and stored lower-cased.
 */
final readonly class DeclareUploadCommand
{
    public function __construct(
        #[Assert\NotNull(message: 'The size field is required.')]
        #[Assert\Range(
            notInRangeMessage: 'The size must be at least one byte and at most 100 MiB.',
            min: 1,
            max: UploadLimits::MAX_ORIGINAL_BYTES,
        )]
        public ?int $size = null,
        #[Assert\Regex(pattern: '/^[0-9a-fA-F]{64}$/D', message: 'The sha256 must be 64 hexadecimal characters.')]
        public ?string $sha256 = null,
        #[Assert\Length(min: 1, max: 255, maxMessage: 'The idempotencyKey must not exceed {{ limit }} characters.')]
        #[Assert\Regex(
            pattern: '/^[\x21-\x7E]+$/D',
            message: 'The idempotencyKey must be printable ASCII without spaces.',
        )]
        public ?string $idempotencyKey = null,
        #[Assert\NotNull(message: 'The retentionReason field is required.')]
        public ?RetentionReason $retentionReason = null,
        #[Assert\NotNull(message: 'The legalBasis field is required.')]
        public ?LegalBasis $legalBasis = null,
        public ?DateTimeImmutable $reviewAt = null,
    ) {
    }

    #[Assert\Callback]
    public function validateKeyNeedsDigest(ExecutionContextInterface $context): void
    {
        if (null !== $this->idempotencyKey && null === $this->sha256) {
            $context->buildViolation('The sha256 field is required when an idempotency key is supplied.')
                ->atPath('sha256')
                ->addViolation()
            ;
        }
    }

    /**
     * The review date as the intent stores and compares it: an instant in UTC at the second, the precision of a
     * `review_at` column that carries no zone — stored with its offset, a `+02:00` value would be written two
     * hours off, and an identical replay would then no longer match it.
     */
    public function normalisedReviewAt(): ?DateTimeImmutable
    {
        if (!$this->reviewAt instanceof DateTimeImmutable) {
            return null;
        }

        return (new DateTimeImmutable('@' . $this->reviewAt->getTimestamp()))
            ->setTimezone(new DateTimeZone('UTC'))
        ;
    }

    /** The digest as the intent stores and compares it. */
    public function normalisedSha256(): ?string
    {
        return null === $this->sha256 ? null : \strtolower($this->sha256);
    }
}
