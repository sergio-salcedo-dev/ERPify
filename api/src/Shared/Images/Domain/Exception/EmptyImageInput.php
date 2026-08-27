<?php

declare(strict_types=1);

namespace Erpify\Shared\Images\Domain\Exception;

use DomainException;

/**
 * The input is zero bytes — rejected before any decode attempt (AC 11).
 */
final class EmptyImageInput extends DomainException implements ImageProcessingException
{
    public function __construct()
    {
        parent::__construct('The image input is empty.');
    }

    public function failureCategory(): FailureCategory
    {
        return FailureCategory::EmptyInput;
    }
}
