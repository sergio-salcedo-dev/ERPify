<?php

declare(strict_types=1);

namespace Erpify\Tests\Unit\Backoffice\Audit\Infrastructure\Http;

use DateTimeImmutable;
use Erpify\Backoffice\Audit\Application\Resource\AuditEventDetailResource;
use Erpify\Backoffice\Audit\Domain\AuditEventDetail;
use Erpify\Backoffice\Audit\Infrastructure\Http\AuditEventDetailResourceMapper;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * @internal
 */
#[CoversClass(AuditEventDetailResourceMapper::class)]
#[CoversClass(AuditEventDetailResource::class)]
final class AuditEventDetailResourceMapperTest extends TestCase
{
    public function testToResourcePinsTheWireShapeMicrosecondInstantAndPassesTheDiffThrough(): void
    {
        $changes = ['changes' => ['name' => ['old' => 'BBVA', 'new' => 'BBVA S.A.']]];

        $resource = $this->mapper()->toResource($this->detail($changes));

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
                'actorErased',
                'metadata',
            ],
            \array_keys(\get_object_vars($resource)),
        );
        // Forensic precision: the microsecond fraction survives, like the timeline mapper.
        $this->assertSame('2026-03-02T10:11:12.123456+00:00', $resource->occurredOn);
        $this->assertSame('change', $resource->level);
        $this->assertSame('BANK_UPDATED', $resource->action);
        $this->assertSame('user', $resource->actorType);
        $this->assertSame('Bank', $resource->resourceType);
        $this->assertFalse($resource->actorErased);
        // The structured diff reaches the wire unaltered — the mapper formats time, never the payload.
        $this->assertSame($changes, $resource->metadata);
    }

    public function testToResourcePassesNullableFieldsAndAnEmptyDiffThrough(): void
    {
        $resource = $this->mapper()->toResource(new AuditEventDetail(
            '0190abcd-1234-7abc-8def-001122334455',
            new DateTimeImmutable('2026-03-02T10:11:12.000000+00:00'),
            'activity',
            'ROUTE_HEALTH',
            'anonymous',
            null,
            '0190abcd-1234-7abc-8def-001122330000',
            null,
            null,
            true,
            [],
        ));

        $this->assertNull($resource->actorId);
        $this->assertNull($resource->resourceType);
        $this->assertNull($resource->resourceId);
        $this->assertTrue($resource->actorErased, 'an erased subject surfaces as such in the detail');
        $this->assertSame([], $resource->metadata, 'a non-change record carries no diff');
    }

    private function mapper(): AuditEventDetailResourceMapper
    {
        return new AuditEventDetailResourceMapper();
    }

    /**
     * @param array<string, mixed> $metadata
     */
    private function detail(array $metadata): AuditEventDetail
    {
        return new AuditEventDetail(
            '0190abcd-1234-7abc-8def-001122334455',
            new DateTimeImmutable('2026-03-02T10:11:12.123456+00:00'),
            'change',
            'BANK_UPDATED',
            'user',
            '11111111-1111-7111-8111-111111111111',
            '0190abcd-1234-7abc-8def-001122330000',
            'Bank',
            '22222222-2222-7222-8222-222222222222',
            false,
            $metadata,
        );
    }
}
