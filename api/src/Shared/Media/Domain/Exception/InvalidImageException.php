<?php

declare(strict_types=1);

namespace Erpify\Shared\Media\Domain\Exception;

use Erpify\Shared\Domain\DomainError;

final class InvalidImageException extends DomainError
{
    public function __construct(
        private readonly string $detail,
    ) {
        parent::__construct();
    }

    public function errorCode(): string
    {
        return 'erpify.media.invalid_image';
    }

    protected function errorMessage(): string
    {
        return $this->detail;
    }
}
