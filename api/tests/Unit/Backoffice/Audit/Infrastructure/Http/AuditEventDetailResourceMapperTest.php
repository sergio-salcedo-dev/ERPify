<?php

declare(strict_types=1);

namespace Erpify\Tests\Unit\Backoffice\Audit\Infrastructure\Http;

use ArrayObject;
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
                'resourceErased',
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
        $this->assertFalse($resource->resourceErased);
        // The structured diff reaches the wire unaltered — the mapper formats time, never the payload; the
        // `changes` map only changes CONTAINER, so its content is compared through the wrapper.
        $this->assertSame(['changes'], \array_keys($resource->metadata->getArrayCopy()));
        $this->assertSame($changes['changes'], $this->changesOf($resource)->getArrayCopy());
    }

    /**
     * `json_decode(…, true)` on the read side hands a stored `{"changes":{}}` back as `['changes' => []]`,
     * which would encode as `"changes":[]` — a list the client's guard refuses, failing the whole envelope.
     * The wrapper is what keeps the empty diff a map; the bytes are asserted in the functional test.
     */
    public function testToResourceKeepsAnEmptyDiffAMapAndLeavesItsSiblingsAlone(): void
    {
        $resource = $this->mapper()->toResource($this->detail(['changes' => [], 'operation' => 'UPDATED']));

        $this->assertSame([], $this->changesOf($resource)->getArrayCopy());
        $this->assertSame('UPDATED', $resource->metadata['operation']);
    }

    /**
     * A record with no `changes` key gets none invented, and a `changes` that is not an array is not
     * rewritten into one — the mapper seals a shape, it never manufactures one.
     */
    public function testToResourceNeverInventsAChangesKey(): void
    {
        $this->assertArrayNotHasKey('changes', $this->mapper()->toResource($this->detail([]))->metadata);
        $this->assertNull(
            $this->mapper()->toResource($this->detail(['changes' => null]))->metadata['changes'],
        );
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
            true,
            [],
        ));

        $this->assertNull($resource->actorId);
        $this->assertNull($resource->resourceType);
        $this->assertNull($resource->resourceId);
        $this->assertTrue($resource->actorErased, 'an erased subject surfaces as such in the detail');
        $this->assertTrue($resource->resourceErased, 'an erased resource surfaces as such in the detail');
        // The property's own type is what guarantees the wire MAP; that an empty one reaches the
        // response as `{}` is asserted over the bytes in `AuditEventDetailFunctionalTest`.
        $this->assertSame([], $resource->metadata->getArrayCopy(), 'a non-change record carries no diff');
    }

    /**
     * A non-empty LIST is not a diff: wrapping it would serve `{"0": {…}}`, which the client's guard admits
     * and renders as a field named "0". Left as a list, it reaches the wire as one and the guard refuses it.
     */
    public function testToResourceLeavesAListShapedChangesUnwrapped(): void
    {
        $list = [['old' => 'BBVA', 'new' => 'BBVA S.A.']];

        $changes = $this->mapper()->toResource($this->detail(['changes' => $list]))->metadata['changes'];

        $this->assertNotInstanceOf(ArrayObject::class, $changes);
        $this->assertSame($list, $changes);
    }

    /**
     * @return ArrayObject<array-key, mixed>
     */
    private function changesOf(AuditEventDetailResource $resource): ArrayObject
    {
        $changes = $resource->metadata['changes'] ?? null;
        $this->assertInstanceOf(ArrayObject::class, $changes, 'a `changes` map must reach the wire as a map');

        return $changes;
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
            false,
            $metadata,
        );
    }
}
