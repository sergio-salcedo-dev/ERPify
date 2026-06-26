<?php

declare(strict_types=1);

namespace Erpify\Tests\Unit\Backoffice\Audit\Infrastructure\Http;

use DateTimeImmutable;
use Erpify\Backoffice\Audit\Application\Resource\AuditTimelineResource;
use Erpify\Backoffice\Audit\Domain\AuditTimelineEntry;
use Erpify\Backoffice\Audit\Infrastructure\Http\AuditTimelineResourceMapper;
use Erpify\Shared\Search\Domain\Page;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * @internal
 */
#[CoversClass(AuditTimelineResourceMapper::class)]
#[CoversClass(AuditTimelineResource::class)]
final class AuditTimelineResourceMapperTest extends TestCase
{
    public function testToListResourcePinsTheWireShapeAndMicrosecondInstant(): void
    {
        $resource = $this->mapper()->toListResource($this->entry());

        $this->assertSame(
            [
                'id',
                'occurredOn',
                'level',
                'action',
                'actorType',
                'actorId',
                'correlationId',
                'resourceType',
                'resourceId',
            ],
            \array_keys(\get_object_vars($resource)),
        );
        // Forensic precision: the microsecond fraction survives (unlike the second-precision bank ATOM).
        $this->assertSame('2026-03-02T10:11:12.123456+00:00', $resource->occurredOn);
        $this->assertSame('activity', $resource->level);
        $this->assertSame('ROUTE_BACKOFFICE_BANK_SEARCH', $resource->action);
        $this->assertSame('user', $resource->actorType);
        $this->assertSame('11111111-1111-7111-8111-111111111111', $resource->actorId);
        $this->assertSame('Bank', $resource->resourceType);
    }

    public function testToListResourcePassesNullableFieldsThrough(): void
    {
        $resource = $this->mapper()->toListResource(new AuditTimelineEntry(
            '0190abcd-1234-7abc-8def-001122334455',
            new DateTimeImmutable('2026-03-02T10:11:12.000000+00:00'),
            'activity',
            'ROUTE_HEALTH',
            'anonymous',
            null,
            '0190abcd-1234-7abc-8def-001122330000',
            null,
            null,
        ));

        $this->assertNull($resource->actorId);
        $this->assertNull($resource->resourceType);
        $this->assertNull($resource->resourceId);
    }

    public function testToListPagePreservesNavigationAndMapsItemsInOrder(): void
    {
        $items = [$this->entry('FIRST'), $this->entry('SECOND')];
        $page = new Page($items, true, false, null, 'next-token', 'prev-token');

        $mapped = $this->mapper()->toListPage($page);

        $this->assertTrue($mapped->hasNext);
        $this->assertFalse($mapped->hasPrev);
        $this->assertNull($mapped->count);
        $this->assertSame('next-token', $mapped->nextCursor);
        $this->assertSame('prev-token', $mapped->prevCursor);
        $this->assertContainsOnlyInstancesOf(AuditTimelineResource::class, $mapped->items);
        $actions = \array_map(static fn (AuditTimelineResource $r): string => $r->action, $mapped->items);
        $this->assertSame(['FIRST', 'SECOND'], $actions);
    }

    private function mapper(): AuditTimelineResourceMapper
    {
        return new AuditTimelineResourceMapper();
    }

    private function entry(string $action = 'ROUTE_BACKOFFICE_BANK_SEARCH'): AuditTimelineEntry
    {
        return new AuditTimelineEntry(
            '0190abcd-1234-7abc-8def-001122334455',
            new DateTimeImmutable('2026-03-02T10:11:12.123456+00:00'),
            'activity',
            $action,
            'user',
            '11111111-1111-7111-8111-111111111111',
            '0190abcd-1234-7abc-8def-001122330000',
            'Bank',
            '22222222-2222-7222-8222-222222222222',
        );
    }
}
