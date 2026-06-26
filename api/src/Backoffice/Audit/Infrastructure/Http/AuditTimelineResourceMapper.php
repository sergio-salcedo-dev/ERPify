<?php

declare(strict_types=1);

namespace Erpify\Backoffice\Audit\Infrastructure\Http;

use Erpify\Backoffice\Audit\Application\Resource\AuditTimelineResource;
use Erpify\Backoffice\Audit\Domain\AuditTimelineEntry;
use Erpify\Shared\Search\Domain\Page;

/**
 * Maps an {@see AuditTimelineEntry} read model to the {@see AuditTimelineResource} wire DTO — the
 * single place that knows the timeline row's shape, so no entity or projection reaches the
 * serializer. `occurredOn` is rendered at microsecond precision (the forensic instant), unlike the
 * second-precision ATOM the bank list uses.
 */
final readonly class AuditTimelineResourceMapper
{
    /** ISO-8601 with the microsecond fraction `occurred_on` (TIMESTAMPTZ(6)) carries. */
    private const string TIMESTAMP_FORMAT = 'Y-m-d\TH:i:s.uP';

    public function toListResource(AuditTimelineEntry $entry): AuditTimelineResource
    {
        return new AuditTimelineResource(
            $entry->id,
            $entry->occurredOn->format(self::TIMESTAMP_FORMAT),
            $entry->level,
            $entry->action,
            $entry->actorType,
            $entry->actorId,
            $entry->correlationId,
            $entry->resourceType,
            $entry->resourceId,
        );
    }

    /**
     * @param Page<AuditTimelineEntry> $page
     *
     * @return Page<AuditTimelineResource>
     */
    public function toListPage(Page $page): Page
    {
        return new Page(
            \array_map($this->toListResource(...), $page->items),
            $page->hasNext,
            $page->hasPrev,
            $page->count,
            $page->nextCursor,
            $page->prevCursor,
        );
    }
}
