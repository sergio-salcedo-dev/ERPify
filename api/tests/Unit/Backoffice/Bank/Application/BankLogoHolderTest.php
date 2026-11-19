<?php

declare(strict_types=1);

namespace Erpify\Tests\Unit\Backoffice\Bank\Application;

use Erpify\Backoffice\Bank\Application\BankFinder;
use Erpify\Backoffice\Bank\Application\BankLogoHolder;
use Erpify\Backoffice\Bank\Domain\Exception\BankNotFoundException;
use Erpify\Shared\ErrorContract\Domain\Exception\NotFound;
use Erpify\Shared\Images\Domain\ImageId;
use Erpify\Tests\Unit\Backoffice\Bank\Domain\Entity\Mother\BankMother;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * The bank as the owner of one image: it reads under the lock, attaches onto the instance that lock loaded, and an
 * absent bank is the bank's own 404 — the marker the compensation catches, never `null`.
 *
 * @internal
 */
#[CoversClass(BankLogoHolder::class)]
final class BankLogoHolderTest extends TestCase
{
    private const string LOGO = '0190b1b2-c3d4-7e5f-8a9b-0c1d2e3f4a5c';

    public function testReadsTheHeldIdUnderTheLock(): void
    {
        $repository = new InMemoryBankRepository(BankMother::withLogo(self::LOGO));

        $held = $this->holder($repository)->lockAndRead(BankMother::DEFAULT_ID);

        $this->assertInstanceOf(ImageId::class, $held);
        $this->assertSame(self::LOGO, $held->toString());
        $this->assertSame(1, $repository->lockedLoads);
    }

    public function testAnImagelessBankReadsNull(): void
    {
        $holder = $this->holder(new InMemoryBankRepository(BankMother::drained()));

        $this->assertNull($holder->lockAndRead(BankMother::DEFAULT_ID));
    }

    public function testAnAbsentBankIsTheBanksOwnNotFoundNeverNull(): void
    {
        $holder = $this->holder(new InMemoryBankRepository());

        try {
            $holder->lockAndRead(BankMother::DEFAULT_ID);
            $this->fail('An absent bank must not read as one that holds nothing.');
        } catch (BankNotFoundException $bankNotFoundException) {
            $implemented = \class_implements($bankNotFoundException);
            $this->assertContains(NotFound::class, $implemented, 'the marker the compensation catches');
        }
    }

    public function testAttachPointsTheBankAtTheNewIdAndSavesItAndClearsOnNull(): void
    {
        $bank = BankMother::drained();
        $repository = new InMemoryBankRepository($bank);
        $holder = $this->holder($repository);

        $holder->attach(BankMother::DEFAULT_ID, ImageId::fromString(self::LOGO));
        $this->assertSame(self::LOGO, $bank->getLogoImageId());

        $holder->attach(BankMother::DEFAULT_ID, null);
        $this->assertNull($bank->getLogoImageId());
        $this->assertSame([$bank, $bank], $repository->saved);
    }

    public function testAttachOnAVanishedBankIsNotFound(): void
    {
        $this->expectException(BankNotFoundException::class);

        $this->holder(new InMemoryBankRepository())->attach(BankMother::DEFAULT_ID, ImageId::generate());
    }

    private function holder(InMemoryBankRepository $repository): BankLogoHolder
    {
        return new BankLogoHolder($repository, new BankFinder($repository));
    }
}
