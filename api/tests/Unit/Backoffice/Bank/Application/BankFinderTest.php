<?php

declare(strict_types=1);

namespace Erpify\Tests\Unit\Backoffice\Bank\Application;

use Erpify\Backoffice\Bank\Application\BankFinder;
use Erpify\Backoffice\Bank\Domain\Exception\BankNotFoundException;
use Erpify\Shared\Uuid\Domain\InvalidUuidException;
use Erpify\Tests\Unit\Backoffice\Bank\Domain\Entity\Mother\BankMother;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * @internal
 */
#[CoversClass(BankFinder::class)]
final class BankFinderTest extends TestCase
{
    public function testReturnsTheBankWhenAWellFormedIdResolvesToARow(): void
    {
        $bank = BankMother::create();

        $found = (new BankFinder(new InMemoryBankRepository($bank)))->find(BankMother::DEFAULT_ID);

        $this->assertSame($bank, $found);
    }

    public function testThrowsNotFoundWhenAWellFormedIdMatchesNoRow(): void
    {
        $this->expectException(BankNotFoundException::class);

        (new BankFinder(new InMemoryBankRepository()))->find(BankMother::DEFAULT_ID);
    }

    public function testRejectsAMalformedIdBeforeTouchingTheRepository(): void
    {
        $this->expectException(InvalidUuidException::class);

        (new BankFinder(new InMemoryBankRepository()))->find('not-a-uuid');
    }

    public function testFindForUpdateReturnsTheBankReadUnderItsLock(): void
    {
        $bank = BankMother::create();
        $repository = new InMemoryBankRepository($bank);

        $this->assertSame($bank, (new BankFinder($repository))->findForUpdate(BankMother::DEFAULT_ID));
        $this->assertSame(1, $repository->lockedLoads);
    }

    public function testFindForUpdateOfABankThatVanishedIsNotFound(): void
    {
        $repository = new InMemoryBankRepository(BankMother::create());
        $repository->vanishesBeforeTheLock = true;

        $this->expectException(BankNotFoundException::class);

        (new BankFinder($repository))->findForUpdate(BankMother::DEFAULT_ID);
    }

    public function testFindForUpdateRejectsAMalformedIdBeforeTouchingTheRepository(): void
    {
        $repository = new InMemoryBankRepository();

        try {
            (new BankFinder($repository))->findForUpdate('not-a-uuid');
            $this->fail('A malformed id must be refused.');
        } catch (InvalidUuidException) {
            $this->assertSame(0, $repository->lockedLoads);
        }
    }
}
