<?php

declare(strict_types=1);

namespace Erpify\Tests\Functional\Backoffice\Audit\Infrastructure\Persistence\Dbal;

use DateTimeImmutable;
use DateTimeZone;
use Doctrine\DBAL\Connection;
use Erpify\Backoffice\Audit\Domain\AuditTimelineEntry;
use Erpify\Backoffice\Audit\Domain\Repository\AuditTimelineRepository;
use Erpify\Backoffice\Audit\Infrastructure\Persistence\Dbal\AuditTimelineKeysetPaginator;
use Erpify\Backoffice\Audit\Infrastructure\Persistence\Dbal\DbalAuditTimelineRepository;
use Erpify\Shared\Audit\Application\AuditLogEntry;
use Erpify\Shared\Audit\Domain\ActorContext;
use Erpify\Shared\Audit\Domain\AuditLevel;
use Erpify\Shared\Audit\Domain\AuditResource;
use Erpify\Shared\Audit\Infrastructure\Persistence\DbalAuditLogWriter;
use Erpify\Shared\Search\Domain\Exception\InvalidCursor;
use Erpify\Shared\Search\Domain\Exception\UnknownSortField;
use Erpify\Shared\Search\Domain\Filter;
use Erpify\Shared\Search\Domain\Filters;
use Erpify\Shared\Search\Domain\NavigationDirection;
use Erpify\Shared\Search\Domain\Page;
use Erpify\Shared\Search\Domain\SearchCriteria;
use Erpify\Shared\Search\Domain\SortDirection;
use Erpify\Shared\Uuid\Domain\Uuid;
use Override;
use PHPUnit\Framework\Attributes\CoversClass;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * Integration lock for the DBAL keyset read path over the entity-free `audit_log` table, driven
 * directly through the repository against REAL Postgres (never SQLite): the default newest-first
 * order, forward/backward navigation that round-trips, microsecond-tie pagination without loss,
 * gap-recovery at both edges, the ascending re-order, and the cursor faults (garbage token,
 * fingerprint drift, unknown sort) that must surface as a 422 rather than mix result sets.
 *
 * Every test runs inside {@see inRolledBackTransaction}: the suite has no DAMA auto-rollback and
 * shares the dev database, so the table is truncated and re-seeded inside a transaction that is
 * always rolled back — the seeded rows never leak.
 *
 * @internal
 *
 * @SuppressWarnings("PHPMD.CouplingBetweenObjects")
 * @SuppressWarnings("PHPMD.TooManyPublicMethods")
 * @SuppressWarnings("PHPMD.TooManyMethods")
 */
#[CoversClass(DbalAuditTimelineRepository::class)]
#[CoversClass(AuditTimelineKeysetPaginator::class)]
#[CoversClass(AuditTimelineEntry::class)]
final class DbalAuditTimelineRepositoryTest extends KernelTestCase
{
    private const int DATASET_SIZE = 8;

    private const string ACTOR_ID = '11111111-1111-7111-8111-111111111111';

    private const string RESOURCE_ID = '22222222-2222-7222-8222-222222222222';

    private Connection $connection;

    private AuditTimelineRepository $repository;

    #[Override]
    protected function setUp(): void
    {
        self::bootKernel();

        $connection = self::getContainer()->get(Connection::class);
        $this->assertInstanceOf(Connection::class, $connection);
        $this->connection = $connection;

        $repository = self::getContainer()->get(AuditTimelineRepository::class);
        $this->assertInstanceOf(DbalAuditTimelineRepository::class, $repository);
        $this->repository = $repository;
    }

    public function testFirstPageIsNewestFirstAndHydratesEveryColumn(): void
    {
        $this->inRolledBackTransaction(function (): void {
            $this->seedTimeline(self::DATASET_SIZE);

            $page = $this->repository->search(new SearchCriteria(limit: 3));

            $this->assertSame(['ACTION_01', 'ACTION_02', 'ACTION_03'], $this->actions($page));
            $this->assertTrue($page->hasNext);
            $this->assertFalse($page->hasPrev);
            $this->assertNotNull($page->nextCursor);

            // ACTION_01 is the newest row (base − 1 min), seeded with a user actor and a Bank resource.
            $first = $this->firstEntry($page);
            $this->assertSame('ACTION_01', $first->action);
            $this->assertSame('user', $first->actorType);
            $this->assertSame(self::ACTOR_ID, $first->actorId);
            $this->assertSame('Bank', $first->resourceType);
            $this->assertSame(self::RESOURCE_ID, $first->resourceId);
            $this->assertFalse($first->actorErased, 'a freshly written row is not erased');
            $this->assertSame(
                '2026-03-01T11:59:00+00:00',
                $first->occurredOn->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d\TH:i:sP'),
            );
        });
    }

    public function testAnErasedActorSurfacesTheErasedFlagThroughTheTimeline(): void
    {
        $this->inRolledBackTransaction(function (): void {
            $this->seedTimeline(2);
            // Flip the newest row to the post-erasure state the GDPR UPDATE materialises.
            $this->connection->executeStatement(
                'UPDATE audit_log SET actor_erased = TRUE WHERE action = :action',
                ['action' => 'ACTION_01'],
            );

            $page = $this->repository->search(new SearchCriteria(limit: 2));

            $this->assertSame(['ACTION_01', 'ACTION_02'], $this->actions($page));
            $this->assertTrue($this->firstEntry($page)->actorErased, 'the erased row reads as erased');
            $second = $page->items[1] ?? null;
            $this->assertInstanceOf(AuditTimelineEntry::class, $second);
            $this->assertFalse($second->actorErased, 'the untouched row stays not-erased');
        });
    }

    public function testForwardNavigationReturnsTheNextDistinctPageWithNullableColumns(): void
    {
        $this->inRolledBackTransaction(function (): void {
            $this->seedTimeline(self::DATASET_SIZE);

            $page1 = $this->repository->search(new SearchCriteria(limit: 3));
            $this->assertNotNull($page1->nextCursor);

            $page2 = $this->repository->search(new SearchCriteria(cursor: $page1->nextCursor, limit: 3));

            $this->assertSame(['ACTION_04', 'ACTION_05', 'ACTION_06'], $this->actions($page2));
            $this->assertSame([], \array_intersect($this->actions($page1), $this->actions($page2)));

            // ACTION_04+ are anonymous with no resource — the hydrator's optional-column path.
            $fourth = $this->firstEntry($page2);
            $this->assertSame('anonymous', $fourth->actorType);
            $this->assertNull($fourth->actorId);
            $this->assertNull($fourth->resourceType);
            $this->assertNull($fourth->resourceId);
        });
    }

    public function testBackwardNavigationRoundTripsToThePreviousPage(): void
    {
        $this->inRolledBackTransaction(function (): void {
            $this->seedTimeline(self::DATASET_SIZE);

            $page1 = $this->repository->search(new SearchCriteria(limit: 3));
            $this->assertNotNull($page1->nextCursor);

            $page2 = $this->repository->search(new SearchCriteria(cursor: $page1->nextCursor, limit: 3));
            $this->assertNotNull($page2->prevCursor);

            $back = $this->repository->search(new SearchCriteria(
                cursor: $page2->prevCursor,
                routingDirection: NavigationDirection::Before,
                limit: 3,
            ));

            $this->assertSame($this->actions($page1), $this->actions($back));
        });
    }

    public function testAscendingSortReversesTheTimeline(): void
    {
        $this->inRolledBackTransaction(function (): void {
            $this->seedTimeline(self::DATASET_SIZE);

            $page = $this->repository->search(new SearchCriteria(
                limit: 3,
                sort: 'occurredOn',
                direction: SortDirection::ASC,
            ));

            // Oldest first: ACTION_08 sits at base − 8 min.
            $this->assertSame(['ACTION_08', 'ACTION_07', 'ACTION_06'], $this->actions($page));
        });
    }

    public function testUnknownSortFieldIsRejected(): void
    {
        $this->inRolledBackTransaction(function (): void {
            $this->seedTimeline(self::DATASET_SIZE);

            $this->expectException(UnknownSortField::class);

            $this->repository->search(new SearchCriteria(sort: 'bogus'));
        });
    }

    public function testMicrosecondTiesArePaginatedWithoutLoss(): void
    {
        $this->inRolledBackTransaction(function (): void {
            $this->truncate();
            $writer = new DbalAuditLogWriter($this->connection);
            $writer->write($this->entry('TIE_LOW', new DateTimeImmutable('2026-03-01T09:00:00.100000+00:00')));
            $writer->write($this->entry('TIE_HIGH', new DateTimeImmutable('2026-03-01T09:00:00.900000+00:00')));

            $page1 = $this->repository->search(new SearchCriteria(limit: 1));
            $this->assertSame(['TIE_HIGH'], $this->actions($page1));
            $this->assertNotNull($page1->nextCursor);

            $page2 = $this->repository->search(new SearchCriteria(cursor: $page1->nextCursor, limit: 1));
            $this->assertSame(['TIE_LOW'], $this->actions($page2));
        });
    }

    public function testEmptyTimelineWithoutACursorIsATerminalEmptyPage(): void
    {
        $this->inRolledBackTransaction(function (): void {
            $this->truncate();

            $page = $this->repository->search(new SearchCriteria(limit: 5));

            $this->assertSame([], $page->items);
            $this->assertFalse($page->hasNext);
            $this->assertFalse($page->hasPrev);
            $this->assertNull($page->nextCursor);
            $this->assertNull($page->prevCursor);
        });
    }

    public function testWalkingForwardPastTheLastRowRecoversWithABackwardAffordance(): void
    {
        $this->inRolledBackTransaction(function (): void {
            $this->seedTimeline(2);

            $page = $this->repository->search(new SearchCriteria(limit: 2));
            $this->assertFalse($page->hasNext);
            $this->assertNotNull($page->nextCursor);

            $past = $this->repository->search(new SearchCriteria(cursor: $page->nextCursor, limit: 2));

            $this->assertSame([], $past->items);
            $this->assertFalse($past->hasNext);
            $this->assertTrue($past->hasPrev);
            $this->assertNull($past->nextCursor);
            $this->assertNotNull($past->prevCursor);
        });
    }

    public function testWalkingBackwardBeforeTheFirstRowRecoversWithAForwardAffordance(): void
    {
        $this->inRolledBackTransaction(function (): void {
            $this->seedTimeline(2);

            $page = $this->repository->search(new SearchCriteria(limit: 2));
            $this->assertNotNull($page->prevCursor);

            $before = $this->repository->search(new SearchCriteria(
                cursor: $page->prevCursor,
                routingDirection: NavigationDirection::Before,
                limit: 2,
            ));

            $this->assertSame([], $before->items);
            $this->assertTrue($before->hasNext);
            $this->assertFalse($before->hasPrev);
            $this->assertNotNull($before->nextCursor);
            $this->assertNull($before->prevCursor);
        });
    }

    public function testGarbageCursorIsRejectedAsInvalidCursor(): void
    {
        $this->inRolledBackTransaction(function (): void {
            $this->seedTimeline(self::DATASET_SIZE);

            $this->expectException(InvalidCursor::class);

            $this->repository->search(new SearchCriteria(cursor: 'not-a-real-cursor'));
        });
    }

    public function testCursorIsRejectedWhenTheQueryFingerprintChanges(): void
    {
        $this->inRolledBackTransaction(function (): void {
            $this->seedTimeline(self::DATASET_SIZE);

            $page1 = $this->repository->search(new SearchCriteria(limit: 3));
            $this->assertNotNull($page1->nextCursor);

            $this->expectException(InvalidCursor::class);

            // Same cursor, but a filter the original page never carried changes the fingerprint.
            $this->repository->search(new SearchCriteria(
                cursor: $page1->nextCursor,
                limit: 3,
                filters: Filters::fromList([Filter::eq('level', 'security')]),
            ));
        });
    }

    public function testAnOmittedLimitDefersToThePolicyDefaultAndStillReturnsRows(): void
    {
        $this->inRolledBackTransaction(function (): void {
            $this->seedTimeline(3);

            $page = $this->repository->search(new SearchCriteria());

            $this->assertCount(3, $page->items);
            $this->assertFalse($page->hasNext);
        });
    }

    private function seedTimeline(int $size): void
    {
        $this->truncate();
        $writer = new DbalAuditLogWriter($this->connection);
        $base = new DateTimeImmutable('2026-03-01T12:00:00.000000+00:00');

        for ($i = 1; $i <= $size; ++$i) {
            $writer->write(AuditLogEntry::create(
                \sprintf('ACTION_%02d', $i),
                0 === $i % 2 ? AuditLevel::SECURITY : AuditLevel::ACTIVITY,
                $i <= 3 ? ActorContext::forUser(self::ACTOR_ID) : ActorContext::anonymous(),
                Uuid::generate(),
                $base->modify(\sprintf('-%d minutes', $i)),
                $i <= 2 ? AuditResource::of('Bank', self::RESOURCE_ID) : null,
            ));
        }
    }

    private function entry(string $action, DateTimeImmutable $occurredOn): AuditLogEntry
    {
        return AuditLogEntry::create(
            $action,
            AuditLevel::ACTIVITY,
            ActorContext::anonymous(),
            Uuid::generate(),
            $occurredOn,
        );
    }

    private function truncate(): void
    {
        $this->connection->executeStatement('TRUNCATE audit_log RESTART IDENTITY');
    }

    /**
     * @param Page<AuditTimelineEntry> $page
     *
     * @return list<string>
     */
    private function actions(Page $page): array
    {
        return \array_map(static fn (AuditTimelineEntry $entry): string => $entry->action, $page->items);
    }

    /**
     * @param Page<AuditTimelineEntry> $page
     */
    private function firstEntry(Page $page): AuditTimelineEntry
    {
        $first = $page->items[0] ?? null;
        $this->assertInstanceOf(AuditTimelineEntry::class, $first);

        return $first;
    }

    /**
     * @param callable(): void $testBody
     */
    private function inRolledBackTransaction(callable $testBody): void
    {
        $this->connection->beginTransaction();

        try {
            $testBody();
        } finally {
            if ($this->connection->isTransactionActive()) {
                $this->connection->rollBack();
            }
        }
    }
}
