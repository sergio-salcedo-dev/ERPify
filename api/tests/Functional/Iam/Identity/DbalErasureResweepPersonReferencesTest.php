<?php

declare(strict_types=1);

namespace Erpify\Tests\Functional\Iam\Identity;

use DateInterval;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Erpify\Iam\Identity\Domain\Entity\ErasureResweep;
use Erpify\Iam\Identity\Domain\Repository\ErasureResweepRepository;
use Erpify\Iam\Identity\Infrastructure\Persistence\Doctrine\DbalErasureResweepPersonReferences;
use Erpify\Shared\Clock\Domain\Clock;
use Erpify\Shared\Clock\Domain\SystemClock;
use Erpify\Shared\Uuid\Domain\Uuid;
use Erpify\Tests\Double\Clock\FixedClock;
use Erpify\Tests\Functional\ResolvesContainerServices;
use Erpify\Tests\Support\PHPUnit\FreezeSystemClockExtension;
use Override;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * Proves the source against real Postgres reads `identity_erasure_resweep.subject_id`, and lists a row only
 * once it has outlived its window by another — an in-window row is the erasure's own instrument, and reporting
 * it would raise a divergence for every erasure.
 *
 * Both rows are seeded, so a read that matched nothing cannot pass as the narrowing working. Runs inside a
 * rolled-back transaction with per-run ids.
 *
 * @internal
 */
#[CoversClass(DbalErasureResweepPersonReferences::class)]
final class DbalErasureResweepPersonReferencesTest extends KernelTestCase
{
    use ResolvesContainerServices;

    private Connection $connection;

    #[Override]
    protected function setUp(): void
    {
        self::bootKernel();

        $this->connection = $this->service(EntityManagerInterface::class)->getConnection();
        $this->connection->beginTransaction();
    }

    protected function tearDown(): void
    {
        FreezeSystemClockExtension::pin();

        if (isset($this->connection) && $this->connection->isTransactionActive()) {
            $this->connection->rollBack();
        }

        parent::tearDown();
    }

    #[Test]
    public function itListsOnlyTheRowsTheResweepShouldAlreadyHaveForgotten(): void
    {
        $overdue = Uuid::generate();
        $inWindow = Uuid::generate();
        $this->scheduleAgo($overdue, 'PT2H1S');
        $this->scheduleAgo($inWindow, 'PT1H30M');

        $seeded = $this->connection->fetchOne(
            'SELECT COUNT(*) FROM identity_erasure_resweep WHERE subject_id IN (CAST(:a AS UUID), CAST(:b AS UUID))',
            ['a' => $overdue, 'b' => $inWindow],
        );
        $this->assertIsNumeric($seeded);
        $this->assertSame(2, (int) $seeded, 'both rows really exist');

        $ids = (new DbalErasureResweepPersonReferences($this->connection, $this->service(Clock::class)))
            ->retainedPersonIds()
        ;

        $this->assertContains($overdue, $ids);
        $this->assertNotContains($inWindow, $ids);
    }

    private function scheduleAgo(string $subjectId, string $age): void
    {
        SystemClock::set(new FixedClock(SystemClock::now()->sub(new DateInterval($age))));
        $resweep = ErasureResweep::scheduleFor($subjectId);
        FreezeSystemClockExtension::pin();

        $this->service(ErasureResweepRepository::class)->save($resweep);
    }
}
