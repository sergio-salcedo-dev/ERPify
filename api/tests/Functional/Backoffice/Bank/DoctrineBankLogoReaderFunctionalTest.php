<?php

declare(strict_types=1);

namespace Erpify\Tests\Functional\Backoffice\Bank;

use Erpify\Backoffice\Bank\Domain\Entity\Bank;
use Erpify\Backoffice\Bank\Domain\Repository\BankLogoReader;
use Erpify\Backoffice\Bank\Infrastructure\Persistence\Doctrine\DoctrineBankLogoReader;
use Erpify\Shared\Uuid\Domain\Uuid;
use Erpify\Tests\Functional\Backoffice\Bank\Fixtures\BankLogoRig;
use Erpify\Tests\Functional\ResolvesContainerServices;
use Override;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * The read the realtime handler composes its row from. It must reach the database on every call: the handler runs in a
 * worker that keeps its entity manager for hours, so an answer taken from the identity map would be the logo the bank
 * held when it was first loaded.
 *
 * @internal
 */
#[CoversClass(DoctrineBankLogoReader::class)]
final class DoctrineBankLogoReaderFunctionalTest extends KernelTestCase
{
    use BankLogoRig;
    use ResolvesContainerServices;

    #[Override]
    protected function setUp(): void
    {
        $this->bootRig();
    }

    protected function tearDown(): void
    {
        $this->tearDownRig();

        parent::tearDown();
    }

    #[Test]
    public function aBankReadsTheLogoItHoldsAndNullWhenItHoldsNone(): void
    {
        $logo = Uuid::generate();
        $reader = $this->service(BankLogoReader::class);

        $this->assertSame($logo, $reader->logoImageIdOf($this->seedBank($logo)));
        $this->assertNull($reader->logoImageIdOf($this->seedBank(null)));
    }

    #[Test]
    public function aBankThatIsGoneReadsNullWithoutFailing(): void
    {
        $this->assertNull($this->service(BankLogoReader::class)->logoImageIdOf(Uuid::generate()));
    }

    /**
     * The bank is already managed with the logo it held, and another connection has since changed it: a `find()` would
     * keep answering the old id for the life of the manager, the scalar read answers the committed one.
     */
    #[Test]
    public function aStaleIdentityMapIsNeverTheAnswer(): void
    {
        $stale = Uuid::generate();
        $current = Uuid::generate();
        $bankId = $this->seedBank($stale);
        $managed = $this->entityManager->find(Bank::class, $bankId);
        $this->assertInstanceOf(Bank::class, $managed);
        $this->outside->executeStatement('UPDATE bank SET logo_image_id = ? WHERE id = ?', [$current, $bankId]);

        $this->assertSame($stale, $this->entityManager->find(Bank::class, $bankId)?->getLogoImageId(), 'the premise');
        $this->assertSame($current, $this->service(BankLogoReader::class)->logoImageIdOf($bankId));

        $this->outside->executeStatement('UPDATE bank SET logo_image_id = NULL WHERE id = ?', [$bankId]);
        $this->assertNull($this->service(BankLogoReader::class)->logoImageIdOf($bankId));
    }
}
