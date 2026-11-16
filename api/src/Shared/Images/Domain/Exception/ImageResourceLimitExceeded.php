<?php

declare(strict_types=1);

namespace Erpify\Shared\Images\Domain\Exception;

use Erpify\Shared\ErrorContract\Domain\Exception\ContentTooLarge;
use Erpify\Shared\ErrorContract\Domain\Exception\DomainException;

/**
 * A resource guard rejected the input before the decoder could consume unbounded memory/CPU
 * — the byte-size guard maps to a distinct {@see FailureCategory} from the declared-dimension and
 * pixel-budget guards, so the observability line can tell them apart. All three answer one wire type: the
 * caller's remedy is the same.
 */
final class ImageResourceLimitExceeded extends DomainException implements ImageProcessingException, ContentTooLarge
{
    public const string TYPE = ImageProblemType::TOO_LARGE;

    private function __construct(string $message, private readonly FailureCategory $category)
    {
        parent::__construct(type: self::TYPE, title: $message);
    }

    public static function inputTooLarge(): self
    {
        return new self('The image input exceeds the maximum allowed byte size.', FailureCategory::InputTooLarge);
    }

    public static function inputDimensionExceeded(): self
    {
        return new self(
            'The declared image dimensions exceed the maximum allowed input dimension.',
            FailureCategory::ResourceLimitExceeded,
        );
    }

    public static function decodedPixelsExceeded(): self
    {
        return new self(
            'The declared image dimensions exceed the maximum allowed decoded-pixel budget.',
            FailureCategory::ResourceLimitExceeded,
        );
    }

    public function failureCategory(): FailureCategory
    {
        return $this->category;
    }
}
