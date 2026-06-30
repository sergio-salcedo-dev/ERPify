<?php

declare(strict_types=1);

namespace Erpify\Tests\Unit\Backoffice\BankAccount\Application;

use Doctrine\ORM\EntityManagerInterface;
use Erpify\Shared\Validation\Application\Validator;
use Symfony\Component\Validator\ConstraintViolationList;
use Symfony\Component\Validator\Validator\ValidatorInterface;

/**
 * Stubs the persistence + validation boundary a transactional bank-account write use case coordinates:
 * an EntityManager whose `wrapInTransaction` runs the callback inline (so a unit test exercises
 * save/remove + publish ordering without a real transaction; atomicity itself is covered by Behat) and
 * a pass-through validator that produces no violations (uniqueness/format are covered functionally).
 *
 * @phpstan-require-extends \PHPUnit\Framework\TestCase
 */
trait BankAccountWriteStubs
{
    private function inlineTransactionEntityManager(): EntityManagerInterface
    {
        $entityManager = $this->createStub(EntityManagerInterface::class);
        $entityManager->method('wrapInTransaction')->willReturnCallback(
            static fn (callable $func): mixed => $func(),
        );

        return $entityManager;
    }

    private function passThroughValidator(): Validator
    {
        $validator = $this->createStub(ValidatorInterface::class);
        $validator->method('validate')->willReturn(new ConstraintViolationList());

        return new Validator($validator);
    }
}
