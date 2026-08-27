<?php

declare(strict_types=1);

namespace Erpify\Shared\Images\Domain\Exception;

use DomainException;
use Throwable;

/**
 * The decoder failed on an already-allowlisted, within-limits input (AC 9) — the translation
 * boundary for the image library's own decode exceptions, which never cross into `Application/`
 * untranslated.
 */
final class ImageDecodingFailed extends DomainException implements ImageProcessingException
{
    public function __construct(Throwable $previous)
    {
        parent::__construct('The image decoder failed to process the input.', previous: $previous);
    }

    public function failureCategory(): FailureCategory
    {
        return FailureCategory::DecodeFailure;
    }
}
