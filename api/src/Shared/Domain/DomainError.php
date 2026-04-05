<?php

declare(strict_types=1);

namespace Erpify\Shared\Domain;

use DomainException;

/**
 * Base for domain-level failures
 */
abstract class DomainError extends DomainException
{
    public function __construct()
    {
        parent::__construct($this->errorMessage());
    }

    abstract public function errorCode(): string;

    abstract protected function errorMessage(): string;
}
