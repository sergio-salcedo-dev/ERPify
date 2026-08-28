<?php

declare(strict_types=1);

namespace Erpify\Shared\Images\Domain\Exception;

use DomainException;

/**
 * A MIME rejection at the decoder's security boundary — never a decision about the
 * conservation contract. The two rejection reasons share this class (both are "the decoder will
 * not touch this content") but map to distinct {@see FailureCategory} values: the detected format
 * itself is not supported, or it does not match what the caller declared (decoder-confusion
 * defense) — a mismatch is rejected even when both formats are individually allowlisted.
 */
final class UnsupportedImageFormat extends DomainException implements ImageProcessingException
{
    private function __construct(string $message, private readonly FailureCategory $category)
    {
        parent::__construct($message);
    }

    public static function notInAllowlist(): self
    {
        return new self(
            'The detected image format is not in the supported allowlist.',
            FailureCategory::UnsupportedFormat,
        );
    }

    public static function mimeMismatch(): self
    {
        return new self(
            'The declared media type does not match the format detected from the content.',
            FailureCategory::MimeMismatch,
        );
    }

    public function failureCategory(): FailureCategory
    {
        return $this->category;
    }
}
