<?php

declare(strict_types=1);

namespace Erpify\Shared\Images\Domain\Exception;

use Erpify\Shared\ErrorContract\Domain\Exception\DomainException;
use Erpify\Shared\ErrorContract\Domain\Exception\InvariantViolation;
use Throwable;

/**
 * The decoder failed on an already-allowlisted, within-limits input — the translation
 * boundary for the image library's own decode exceptions, which never cross into `Application/`
 * untranslated.
 */
final class ImageDecodingFailed extends DomainException implements ImageProcessingException, InvariantViolation
{
    public const string TYPE = ImageProblemType::INVALID;

    public function __construct(Throwable $previous)
    {
        parent::__construct(
            type: self::TYPE,
            title: 'The image decoder failed to process the input.',
            previous: $previous,
        );
    }

    public function failureCategory(): FailureCategory
    {
        return FailureCategory::DecodeFailure;
    }
}
