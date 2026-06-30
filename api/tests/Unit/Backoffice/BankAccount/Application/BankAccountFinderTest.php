<?php

declare(strict_types=1);

namespace Erpify\Tests\Unit\Backoffice\BankAccount\Application;

use Erpify\Backoffice\BankAccount\Application\BankAccountFinder;
use Erpify\Backoffice\BankAccount\Domain\Exception\BankAccountNotFoundException;
use Erpify\Shared\Uuid\Domain\InvalidUuidException;
use Erpify\Tests\Unit\Backoffice\BankAccount\Domain\Entity\Mother\BankAccountMother;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * @internal
 */
#[CoversClass(BankAccountFinder::class)]
final class BankAccountFinderTest extends TestCase
{
    public function testReturnsTheAccountWhenAWellFormedIdResolvesToARow(): void
    {
        $account = BankAccountMother::create();
        $finder = new BankAccountFinder(new InMemoryBankAccountRepository($account));

        $found = $finder->find(BankAccountMother::DEFAULT_ID);

        $this->assertSame($account, $found);
    }

    public function testThrowsNotFoundWhenAWellFormedIdMatchesNoRow(): void
    {
        $this->expectException(BankAccountNotFoundException::class);

        (new BankAccountFinder(new InMemoryBankAccountRepository()))->find(BankAccountMother::DEFAULT_ID);
    }

    public function testRejectsAMalformedIdBeforeTouchingTheRepository(): void
    {
        $this->expectException(InvalidUuidException::class);

        (new BankAccountFinder(new InMemoryBankAccountRepository()))->find('not-a-uuid');
    }
}
