<?php

declare(strict_types=1);

namespace Erpify\Tests\Functional\Backoffice\Audit\Infrastructure\Persistence\Dbal;

use DateTimeImmutable;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Query\QueryBuilder;
use Erpify\Backoffice\Audit\Infrastructure\Persistence\Dbal\AuditTimelineKeysetPaginator;
use Erpify\Backoffice\Audit\Infrastructure\Persistence\Dbal\AuditTimelineKeysetSource;
use Erpify\Shared\Audit\Application\AuditLogEntry;
use Erpify\Shared\Audit\Domain\ActorContext;
use Erpify\Shared\Audit\Domain\AuditLevel;
use Erpify\Shared\Audit\Domain\AuditProvenance;
use Erpify\Shared\Audit\Infrastructure\Persistence\DbalAuditLogWriter;
use Erpify\Shared\Search\Domain\Exception\InvalidCursor;
use Erpify\Shared\Search\Domain\Exception\InvalidCursorCause;
use Erpify\Shared\Search\Domain\Page;
use Erpify\Shared\Search\Domain\SearchCriteria;
use Erpify\Shared\Search\Infrastructure\Persistence\Doctrine\SearchFieldMap;
use Erpify\Shared\Search\Infrastructure\Persistence\Doctrine\SortFieldMap;
use Erpify\Shared\Uuid\Domain\Uuid;
use Override;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use stdClass;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * The audit timeline's cursor is bound to the base query its repository authored, the DBAL twin of
 * `KeysetBaseQueryScopeTest`. Without the base query in the fingerprint, two audit listings whose scopes live
 * in their base queries rather than in the request's filters would mint identical fingerprints, so a cursor
 * from one would be accepted on the other and walk it from a foreign boundary. Driven through the paginator the
 * container wires, against real Postgres, so the SQL text compared is the one DBAL renders for this platform.
 *
 * @internal
 */
#[CoversClass(AuditTimelineKeysetSource::class)]
final class AuditTimelineBaseQueryScopeTest extends KernelTestCase
{
    private const int PAGE_LIMIT = 2;

    private Connection $connection;

    private AuditTimelineKeysetPaginator $paginator;

    #[Override]
    protected function setUp(): void
    {
        self::bootKernel();

        $connection = self::getContainer()->get(Connection::class);
        $this->assertInstanceOf(Connection::class, $connection);
        $this->connection = $connection;

        $paginator = self::getContainer()->get(AuditTimelineKeysetPaginator::class);
        $this->assertInstanceOf(AuditTimelineKeysetPaginator::class, $paginator);
        $this->paginator = $paginator;
    }

    #[Test]
    public function aCursorMintedOnOneBaseQueryIsRejectedOnAScopedOne(): void
    {
        $this->inRolledBackTransaction(function (): void {
            $this->seedTimeline();

            $page = $this->paginate($this->plainQuery());
            $this->assertNotNull($page->nextCursor, 'The seeded timeline must expose a next cursor.');

            try {
                $this->paginate($this->scopedQuery(AuditLevel::SECURITY), $page->nextCursor);
                $this->fail('A cursor minted on a different base query must be rejected.');
            } catch (InvalidCursor $invalidCursor) {
                // Rejected because the recomputed fingerprint no longer matches, not for an earlier reason.
                $this->assertSame(InvalidCursorCause::Fingerprint, $invalidCursor->cause);
            }
        });
    }

    #[Test]
    public function aCursorRoundTripsOnAnIdenticalBaseQueryBuiltForTheNextRequest(): void
    {
        $this->inRolledBackTransaction(function (): void {
            $this->seedTimeline();

            $first = $this->paginate($this->scopedQuery(AuditLevel::ACTIVITY));
            $this->assertNotNull($first->nextCursor);

            // A fresh builder, as the next HTTP request would make: the identity is the SQL, not the object.
            $second = $this->paginate($this->scopedQuery(AuditLevel::ACTIVITY), $first->nextCursor);

            $this->assertNotEmpty($second->items);
            $this->assertNotSame($this->ids($first), $this->ids($second));
        });
    }

    /**
     * The base query binds its SHAPE, never its bound values: the same scope over another value shares the
     * fingerprint. Binding values would turn an id in a route into part of the cursor's identity, which is the
     * request filters' job, and would make an array parameter's length shift it.
     */
    #[Test]
    public function theBaseQueryIsBoundByItsShapeNotItsBoundValues(): void
    {
        $this->inRolledBackTransaction(function (): void {
            $this->seedTimeline();

            $page = $this->paginate($this->scopedQuery(AuditLevel::ACTIVITY));
            $this->assertNotNull($page->nextCursor);

            $replayed = $this->paginate($this->scopedQuery(AuditLevel::SECURITY), $page->nextCursor);

            $this->assertInstanceOf(Page::class, $replayed);
        });
    }

    /**
     * @return Page<object>
     */
    private function paginate(QueryBuilder $baseQuery, ?string $cursor = null): Page
    {
        return $this->paginator->paginate(
            $baseQuery,
            'a',
            new SearchCriteria(cursor: $cursor, limit: self::PAGE_LIMIT),
            new SearchFieldMap([]),
            new SortFieldMap(['occurredOn' => 'a.occurred_on']),
            static function (array $row): object {
                $entry = new stdClass();
                $entry->id = $row['id'];

                return $entry;
            },
        );
    }

    private function plainQuery(): QueryBuilder
    {
        return $this->connection->createQueryBuilder()->select('a.id', 'a.occurred_on')->from('audit_log', 'a');
    }

    private function scopedQuery(AuditLevel $level): QueryBuilder
    {
        return $this->plainQuery()->where('a.level = :scope')->setParameter('scope', $level->value);
    }

    private function seedTimeline(): void
    {
        $this->connection->executeStatement('TRUNCATE audit_log RESTART IDENTITY');
        $writer = new DbalAuditLogWriter($this->connection);
        $base = new DateTimeImmutable('2026-03-01T12:00:00.000000+00:00');

        for ($i = 1; $i <= 6; ++$i) {
            foreach ([AuditLevel::ACTIVITY, AuditLevel::SECURITY] as $level) {
                $writer->write(AuditLogEntry::create(
                    \sprintf('SCOPE_PROBE_%02d', $i),
                    $level,
                    new AuditProvenance(ActorContext::anonymous(), Uuid::generate(), $base->modify("-{$i} minutes")),
                ));
            }
        }
    }

    /**
     * @param Page<object> $page
     *
     * @return list<mixed>
     */
    private function ids(Page $page): array
    {
        return \array_map(static fn (object $entry): mixed => $entry->id ?? null, $page->items);
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
            $this->connection->rollBack();
        }
    }
}
