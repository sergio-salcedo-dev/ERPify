<?php

declare(strict_types=1);

namespace Erpify\Backoffice\Audit\Infrastructure\Persistence\Dbal;

use DateTimeImmutable;
use Doctrine\DBAL\Connection;
use Erpify\Backoffice\Audit\Domain\AuditTimelineEntry;
use Erpify\Backoffice\Audit\Domain\Repository\AuditTimelineRepository;
use Erpify\Shared\Search\Domain\FilterOperator;
use Erpify\Shared\Search\Domain\Page;
use Erpify\Shared\Search\Domain\SearchCriteria;
use Erpify\Shared\Search\Infrastructure\Persistence\Doctrine\FieldMapping;
use Erpify\Shared\Search\Infrastructure\Persistence\Doctrine\SearchFieldMap;
use Erpify\Shared\Search\Infrastructure\Persistence\Doctrine\SortFieldMap;
use Override;
use Symfony\Component\DependencyInjection\Attribute\AsAlias;
use UnexpectedValueException;

/**
 * {@see AuditTimelineRepository} over the append-only `audit_log` table by plain DBAL — no Doctrine
 * entity (the write side is `Shared/Audit`, and the table stays entity-free by design). Its sole
 * search responsibility is to hand the {@see AuditTimelineKeysetPaginator} a base query builder plus
 * the filter/sort allow-lists; ordering, the keyset predicate, the limit and cursor encoding are the
 * paginator's monopoly. The returned {@see Page} carries OPAQUE cursors — link materialization is
 * the responder's job.
 *
 * @SuppressWarnings("PHPMD.CouplingBetweenObjects")
 */
#[AsAlias(AuditTimelineRepository::class)]
final readonly class DbalAuditTimelineRepository implements AuditTimelineRepository
{
    private const string TABLE = 'audit_log';

    private const string ALIAS = 'a';

    public function __construct(
        private Connection $connection,
        private AuditTimelineKeysetPaginator $paginator,
    ) {
    }

    /**
     * @return Page<AuditTimelineEntry>
     */
    #[Override]
    public function search(SearchCriteria $criteria): Page
    {
        $queryBuilder = $this->connection->createQueryBuilder()
            ->select(
                'a.id',
                'a.occurred_on',
                'a.level',
                'a.action',
                'a.actor_type',
                'a.actor_id',
                'a.correlation_id',
                'a.resource_type',
                'a.resource_id',
            )
            ->from(self::TABLE, self::ALIAS)
        ;

        /** @var Page<AuditTimelineEntry> $page */
        $page = $this->paginator->paginate(
            $queryBuilder,
            self::ALIAS,
            $criteria,
            $this->searchFieldMap(),
            $this->sortFieldMap(),
            $this->hydrate(...),
        );

        return $page;
    }

    /**
     * The filterable surface (NFR9 backs the indexed ones): the timeline filters by actor, level,
     * action, resource and date range. UUID columns are `eq`-only — a raw-DBAL `IN` over a uuid
     * column would need a `uuid[]` bind no use case asks for; `eq` keeps it index-backed and simple.
     */
    private function searchFieldMap(): SearchFieldMap
    {
        $inOrEq = [FilterOperator::Eq, FilterOperator::In];
        $textOperators = [FilterOperator::Eq, FilterOperator::In, FilterOperator::Contains];
        $rangeOperators = [FilterOperator::Gt, FilterOperator::Gte, FilterOperator::Lt, FilterOperator::Lte];

        return new SearchFieldMap([
            'occurredOn' => new FieldMapping('a.occurred_on', operators: $rangeOperators, requiresDateTimeValues: true),
            'level' => new FieldMapping('a.level', operators: $inOrEq),
            'actorType' => new FieldMapping('a.actor_type', operators: $inOrEq),
            'actorId' => new FieldMapping('a.actor_id', operators: [FilterOperator::Eq], requiresUuidValues: true),
            'action' => new FieldMapping('a.action', operators: $textOperators),
            'resourceType' => new FieldMapping('a.resource_type', operators: $inOrEq),
            'resourceId' => new FieldMapping(
                'a.resource_id',
                operators: [FilterOperator::Eq],
                requiresUuidValues: true,
            ),
            'correlationId' => new FieldMapping(
                'a.correlation_id',
                operators: [FilterOperator::Eq],
                requiresUuidValues: true,
            ),
        ]);
    }

    private function sortFieldMap(): SortFieldMap
    {
        // Only the forensic instant is sortable; the keyset always tie-breaks on `id`.
        return new SortFieldMap(['occurredOn' => 'a.occurred_on']);
    }

    /**
     * @param array<string, mixed> $row
     */
    private function hydrate(array $row): AuditTimelineEntry
    {
        return new AuditTimelineEntry(
            $this->requiredString($row, 'id'),
            new DateTimeImmutable($this->requiredString($row, 'occurred_on')),
            $this->requiredString($row, 'level'),
            $this->requiredString($row, 'action'),
            $this->requiredString($row, 'actor_type'),
            $this->optionalString($row, 'actor_id'),
            $this->requiredString($row, 'correlation_id'),
            $this->optionalString($row, 'resource_type'),
            $this->optionalString($row, 'resource_id'),
        );
    }

    /**
     * @param array<string, mixed> $row
     */
    private function requiredString(array $row, string $column): string
    {
        $value = $row[$column] ?? null;

        if (!\is_string($value)) {
            throw new UnexpectedValueException(\sprintf('audit_log.%s must be a non-null string.', $column));
        }

        return $value;
    }

    /**
     * @param array<string, mixed> $row
     */
    private function optionalString(array $row, string $column): ?string
    {
        $value = $row[$column] ?? null;

        if (null === $value) {
            return null;
        }

        if (!\is_string($value)) {
            throw new UnexpectedValueException(\sprintf('audit_log.%s must be a string when present.', $column));
        }

        return $value;
    }
}
