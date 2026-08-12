<?php

declare(strict_types=1);

namespace Erpify\Tests\Unit\Backoffice\BankAccount\Application;

use Erpify\Shared\Validation\Application\Validator;
use Symfony\Component\Validator\ConstraintViolationList;
use Symfony\Component\Validator\Validator\ValidatorInterface;

/**
 * Stubs the validation boundary a bank-account write use case coordinates: a pass-through validator that
 * produces no violations (uniqueness and format are covered functionally).
 *
 * @phpstan-require-extends \PHPUnit\Framework\TestCase
 */
trait BankAccountWriteStubs
{
    private function passThroughValidator(): Validator
    {
        $validator = $this->createStub(ValidatorInterface::class);
        $validator->method('validate')->willReturn(new ConstraintViolationList());

        return new Validator($validator);
    }
}
