<?php

declare(strict_types=1);

namespace Erpify\Shared\Images\Domain\Exception;

use DomainException;
use Throwable;

/**
 * Normalization or re-encoding failed on an already-decoded image — the translation
 * boundary for the image library's own normalize/encode exceptions.
 */
final class ImageProcessingFailed extends DomainException implements ImageProcessingException
{
    public function __construct(Throwable $previous)
    {
        parent::__construct('Normalizing or encoding the image failed.', previous: $previous);
    }

    public function failureCategory(): FailureCategory
    {
        return FailureCategory::ProcessingFailure;
    }
}
