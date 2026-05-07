<?php

declare(strict_types=1);

namespace Erpify\Shared\Media\Domain\Exception;

use Erpify\Shared\Domain\Exception\DomainException;
use Erpify\Shared\Domain\Exception\InvariantViolation;

final class InvalidImageException extends DomainException implements InvariantViolation
{
    private const string DEFAULT_FORM_FIELD = 'image';

    public function __construct(string $detail, string $formField = self::DEFAULT_FORM_FIELD)
    {
        parent::__construct(
            type: 'invalid-image',
            title: $detail,
            context: ['formField' => $formField],
        );
    }

    public function formField(): string
    {
        $context = $this->context();

        return \is_string($context['formField'] ?? null) ? $context['formField'] : self::DEFAULT_FORM_FIELD;
    }
}
