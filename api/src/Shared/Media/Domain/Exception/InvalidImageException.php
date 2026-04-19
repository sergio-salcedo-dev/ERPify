<?php

declare(strict_types=1);

namespace Erpify\Shared\Media\Domain\Exception;

use Erpify\Shared\Domain\DomainError;

final class InvalidImageException extends DomainError
{
    public function __construct(
        private readonly string $detail,
        private readonly string $formField = 'image',
    ) {
        parent::__construct();
    }

    #[\Override]
    public function errorCode(): string
    {
        return 'erpify.media.invalid_image';
    }

    public function formField(): string
    {
        return $this->formField;
    }

    #[\Override]
    protected function errorMessage(): string
    {
        return $this->detail;
    }
}
