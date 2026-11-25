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

    /**
     * The level proves who wrote a diff, not what a field in it holds, and nothing on the erasure path rewrites
     * `metadata`: a field is served in clear only when it is sealed or classified as non-personal for the row's
     * resource type. The rest keeps its place in the diff, both sides withheld.
     */
    public function testAnUnclassifiedFieldIsWithheldWhileClassifiedAndSealedOnesAreServed(): void
    {
        $sealed = ['__enc__' => 'ciphertext'];
        $metadata = $this->mapper()->toResource($this->detail([
            'changes' => [
                'name' => ['old' => 'Acme', 'new' => 'Acme Bank'],
                'ownerEmail' => ['old' => 'jane.doe@example.test', 'new' => 'jane.doe@example.org'],
                'holderName' => ['old' => $sealed, 'new' => $sealed],
                'iban' => ['old' => null, 'new' => $sealed],
            ],
        ], 'change'))->metadata;

        $changes = $metadata['changes'];
        $this->assertInstanceOf(ArrayObject::class, $changes);

        $this->assertSame(['old' => 'Acme', 'new' => 'Acme Bank'], $changes['name'], 'a classified clear field is served');
        $this->assertSame($sealed, $changes['holderName']['old'], 'a sealed value is served as sealed');
        $this->assertSame(['old' => null, 'new' => $sealed], $changes['iban'], 'an absent side beside a sealed one is served');
        $this->assertSame(
            ['old' => AuditEventDetailResourceMapper::WITHHELD, 'new' => AuditEventDetailResourceMapper::WITHHELD],
            $changes['ownerEmail'],
            'a field nobody classified keeps its place with both sides withheld',
        );
        $this->assertStringNotContainsString('jane.doe', \json_encode($changes->getArrayCopy(), JSON_THROW_ON_ERROR));
    }

    public function testAClearFieldOfAnotherResourceTypeIsNotServedUnderThisOne(): void
    {
        $metadata = $this->mapper()->toResource($this->detail(
            ['changes' => ['holderName' => ['old' => 'Jane Doe', 'new' => 'Jane Roe']]],
            'change',
        ))->metadata;

        $changes = $metadata['changes'];
        $this->assertInstanceOf(ArrayObject::class, $changes);
        $this->assertSame(
            ['old' => AuditEventDetailResourceMapper::WITHHELD, 'new' => AuditEventDetailResourceMapper::WITHHELD],
            $changes['holderName'],
            'a personal field of BankAccount sent in clear on a Bank row is not a Bank field',
        );
    }

    /**
     * @return iterable<string, array{mixed}>
     */
    public static function provideMalformedPairs(): iterable
    {
        yield 'a scalar instead of a pair' => ['Acme'];
        yield 'a pair missing a side' => [['new' => 'Acme']];
        yield 'a sealed-looking side that carries a second key' => [
            ['old' => ['__enc__' => 'x', 'email' => 'jane.doe@example.test'], 'new' => null],
        ];
    }

    #[DataProvider('provideMalformedPairs')]
    public function testAFieldThatIsNotAWellFormedDiffPairIsWithheldWhateverItHolds(mixed $pair): void
    {
        $metadata = $this->mapper()->toResource($this->detail(['changes' => ['notes' => $pair]], 'change'))->metadata;

        $changes = $metadata['changes'];
        $this->assertInstanceOf(ArrayObject::class, $changes);
        $this->assertSame(
            ['old' => AuditEventDetailResourceMapper::WITHHELD, 'new' => AuditEventDetailResourceMapper::WITHHELD],
            $changes['notes'],
        );
    }

    public function testARowWithNoResourceTypeServesNothingInClear(): void
    {
        $detail = new AuditEventDetail(
            '0190abcd-1234-7abc-8def-001122334455',
            new DateTimeImmutable('2026-03-02T10:11:12.123456+00:00'),
            'change',
            'BANK_UPDATED',
            'user',
            '11111111-1111-7111-8111-111111111111',
            '0190abcd-1234-7abc-8def-001122330000',
            null,
            null,
            false,
            false,
            ['changes' => ['name' => ['old' => 'Acme', 'new' => 'Acme Bank']]],
        );

        $changes = $this->mapper()->toResource($detail)->metadata['changes'];
        $this->assertInstanceOf(ArrayObject::class, $changes);
        $this->assertSame(AuditEventDetailResourceMapper::WITHHELD, $changes['name']['old']);
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
