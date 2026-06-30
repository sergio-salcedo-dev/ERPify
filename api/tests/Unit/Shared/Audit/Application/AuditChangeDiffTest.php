<?php

declare(strict_types=1);

namespace Erpify\Tests\Unit\Shared\Audit\Application;

use DateTimeImmutable;
use Erpify\Shared\Audit\Application\AuditChangeDiff;
use Erpify\Shared\Audit\Domain\AuditLevel;
use Erpify\Shared\Audit\Domain\AuditWriteOperation;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use stdClass;

/**
 * @internal
 */
#[CoversClass(AuditChangeDiff::class)]
final class AuditChangeDiffTest extends TestCase
{
    public function testItMapsEachChangedFieldToItsOldAndNewValue(): void
    {
        $diff = (new AuditChangeDiff())->of([
            'name' => ['BBVA', 'BBVA S.A.'],
            'shortName' => ['BBVA', 'BBVASA'],
        ]);

        $this->assertSame([
            'changes' => [
                'name' => ['old' => 'BBVA', 'new' => 'BBVA S.A.'],
                'shortName' => ['old' => 'BBVA', 'new' => 'BBVASA'],
            ],
        ], $diff);
    }

    public function testItRepresentsAnInsertWithNullPriorValues(): void
    {
        $diff = (new AuditChangeDiff())->of(['name' => [null, 'BBVA']]);

        $this->assertSame(['changes' => ['name' => ['old' => null, 'new' => 'BBVA']]], $diff);
    }

    public function testItKeepsJsonSafeScalarsAndStringifiesADate(): void
    {
        $diff = (new AuditChangeDiff())->of([
            'createdAt' => [null, new DateTimeImmutable('2026-06-30T10:00:00+00:00')],
            'count' => [1, 2],
            'active' => [false, true],
        ]);

        $this->assertSame([
            'changes' => [
                'createdAt' => ['old' => null, 'new' => '2026-06-30T10:00:00+00:00'],
                'count' => ['old' => 1, 'new' => 2],
                'active' => ['old' => false, 'new' => true],
            ],
        ], $diff);
    }

    public function testItReducesAnOpaqueObjectToItsTypeSoNoStateLeaksIntoTheTrail(): void
    {
        $diff = (new AuditChangeDiff())->of(['media' => [null, new stdClass()]]);

        $this->assertSame(['changes' => ['media' => ['old' => null, 'new' => 'stdClass']]], $diff);
    }

    public function testItRecordsABackedEnumAsItsBackingValueNotItsClassName(): void
    {
        $diff = (new AuditChangeDiff())->of(['level' => [AuditLevel::ACTIVITY, AuditLevel::CHANGE]]);

        $this->assertSame(['changes' => ['level' => ['old' => 'activity', 'new' => 'change']]], $diff);
    }

    public function testItRecordsAPureEnumAsItsCaseName(): void
    {
        $diff = (new AuditChangeDiff())->of(['operation' => [null, AuditWriteOperation::DELETED]]);

        $this->assertSame(['changes' => ['operation' => ['old' => null, 'new' => 'DELETED']]], $diff);
    }

    public function testItSkipsAChangeThatIsNotASimpleOldToNewPair(): void
    {
        // Doctrine reports a to-many change as the collection itself, not an [old, new] pair; field-level
        // scalar capture is the scope here, so such a change is left out rather than mangled.
        $diff = (new AuditChangeDiff())->of([
            'name' => ['BBVA', 'BBVA S.A.'],
            'accounts' => new stdClass(),
        ]);

        $this->assertSame(['changes' => ['name' => ['old' => 'BBVA', 'new' => 'BBVA S.A.']]], $diff);
    }
}
