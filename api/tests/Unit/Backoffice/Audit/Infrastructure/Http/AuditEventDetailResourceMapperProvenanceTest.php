<?php

declare(strict_types=1);

namespace Erpify\Tests\Unit\Backoffice\Audit\Infrastructure\Http;

use ArrayObject;
use DateTimeImmutable;
use Erpify\Backoffice\Audit\Domain\AuditEventDetail;
use Erpify\Backoffice\Audit\Infrastructure\Http\AuditEventDetailResourceMapper;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The read-side half of the provenance rule: a `changes` map is served only on a `change` row. The shape rules
 * that hold on every row are pinned in {@see AuditEventDetailResourceMapperTest}.
 *
 * @internal
 */
#[CoversClass(AuditEventDetailResourceMapper::class)]
final class AuditEventDetailResourceMapperProvenanceTest extends TestCase
{
    /**
     * The level is the diff's provenance, the shape is not: only a `change` row went through the sealer, so a
     * map on any other row is withheld whole — keys too, since they came from the same unsealed writer —
     * however exactly it imitates a diff, and as a scalar so the client degrades it to an unreadable diff.
     */
    #[DataProvider('provideToResourceWithholdsAMapOnARowThatIsNotAChangeRowCases')]
    public function testToResourceWithholdsAMapOnARowThatIsNotAChangeRow(string $level): void
    {
        $changes = ['jane.doe@example.test' => ['old' => 'jane.doe@example.test', 'new' => 'jane@example.test']];

        $metadata = $this->mapper()->toResource($this->detail(['changes' => $changes], $level))->metadata;

        $this->assertSame(AuditEventDetailResourceMapper::WITHHELD, $metadata['changes']);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function provideToResourceWithholdsAMapOnARowThatIsNotAChangeRowCases(): iterable
    {
        yield 'activity' => ['activity'];
        yield 'security' => ['security'];
        yield 'a token outside the enum' => ['CHANGE'];
    }

    /**
     * No diff builder wrote a list on a row that is not `change`, so it is withheld whole like a map — a list of
     * withheld values would make the client refuse the whole activity or security event over a writer's mistake.
     * A map keyed `"0"`, `"1"`, … decodes as such a list and lands here too.
     */
    public function testToResourceWithholdsAListWholeOnARowThatIsNotAChangeRow(): void
    {
        $list = [['old' => 'jane.doe@example.test', 'new' => 'jane@example.test'], 'loose'];

        $metadata = $this->mapper()->toResource($this->detail(['changes' => $list], 'security'))->metadata;

        $this->assertSame(AuditEventDetailResourceMapper::WITHHELD, $metadata['changes']);
    }

    /**
     * An empty map carries nothing to withhold on any row, so it stays the `{}` the client's guard admits.
     */
    public function testToResourceKeepsAnEmptyMapAMapOnARowThatIsNotAChangeRow(): void
    {
        $metadata = $this->mapper()->toResource($this->detail(['changes' => []], 'activity'))->metadata;

        $this->assertInstanceOf(ArrayObject::class, $metadata['changes']);
        $this->assertSame([], $metadata['changes']->getArrayCopy());
    }

    private function mapper(): AuditEventDetailResourceMapper
    {
        return new AuditEventDetailResourceMapper();
    }

    /**
     * @param array<string, mixed> $metadata
     */
    private function detail(array $metadata, string $level): AuditEventDetail
    {
        return new AuditEventDetail(
            '0190abcd-1234-7abc-8def-001122334455',
            new DateTimeImmutable('2026-03-02T10:11:12.123456+00:00'),
            $level,
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
