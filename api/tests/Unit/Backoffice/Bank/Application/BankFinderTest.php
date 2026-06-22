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
}
