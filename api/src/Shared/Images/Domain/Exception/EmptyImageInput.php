<?php

declare(strict_types=1);

namespace Erpify\Shared\Images\Domain\Exception;

use Erpify\Shared\ErrorContract\Domain\Exception\DomainException;
use Erpify\Shared\ErrorContract\Domain\Exception\InvariantViolation;

/**
 * The input is zero bytes — rejected before any decode attempt.
 */
final class EmptyImageInput extends DomainException implements ImageProcessingException, InvariantViolation
{
    public const string TYPE = ImageProblemType::INVALID;

    public function __construct()
    {
        parent::__construct(type: self::TYPE, title: 'The image input is empty.');
    }

    public function failureCategory(): FailureCategory
    {
        return FailureCategory::EmptyInput;
    }
}
