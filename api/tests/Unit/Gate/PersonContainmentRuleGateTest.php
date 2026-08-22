<?php

declare(strict_types=1);

namespace Erpify\Tests\Unit\Gate;

use Erpify\Tests\Support\CapturedPersonReferences;
use Erpify\Tests\Unit\Gate\Fixture\PersonCapture\CapturedPersonFixtureEntity;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Falsifiability of the containment rule: no `person` column may sit on an aggregate whose writes the
 * regulatory trail captures.
 *
 * Its own class rather than another method on {@see PersonReferenceRulesGateTest}, because the rule answers a
 * different question from the rest of that file. The others are about the REGISTRY — is every column
 * classified, does every declaration have a column, is the owner named. This one is about a collision between
 * two markers that each look correct alone: `#[PersonSubjectReference]` says a column holds a person's id and
 * names its eraser, {@see \Erpify\Shared\Audit\Domain\AuditedEntity} opts the aggregate into field-level
 * change-data-capture, and an entity carrying both copies that id into `audit_log.metadata` as cleartext on
 * every write — an axis no mutation policy reaches, so the copy outlives the erasure the registry promises.
 *
 * The real tree cannot exercise it: its only `AuditedEntity` implementors are `Bank` and `BankAccount`, and
 * every column of both is `non-person`, so the rule asserted over `src` is satisfied by an empty intersection
 * and stays green whatever it says. {@see PersonReferenceGateTest} makes that assertion; this class is what
 * proves it would fire.
 *
 * @internal
 */
#[CoversNothing]
final class PersonContainmentRuleGateTest extends TestCase
{
    private const string OWNER = 'src/Iam/Session/Application/PurgeUserSessions.php';

    private const string UNCAPTURED = 'Erpify\Tests\Unit\Gate\Fixture\PersonReference'
        . '\PersonReferenceFixtureEntity::$subjectId';

    #[Test]
    public function aPersonColumnOnAnAggregateTheTrailCapturesIsReported(): void
    {
        // Each branch gets its own member of the input, so a rule that stopped weighing one of them goes red
        // here rather than silently narrowing.
        $captured = CapturedPersonFixtureEntity::class . '::$subjectId';

        $reported = CapturedPersonReferences::in([
            $captured => self::OWNER,
            // A person column on an aggregate the trail does NOT capture: the ordinary, correct shape.
            self::UNCAPTURED => self::OWNER,
            // Non-person on a captured aggregate: change-data-capture copies it and that is fine — the rule
            // weighs the classification, never the marker alone.
            CapturedPersonFixtureEntity::class . '::$tenantId' => null,
            // Unparseable, and the shape that used to slip through: split on `::` instead of `::$` and a key
            // like this collapses to the empty string, which `is_a()` answers false for — so a mistyped
            // person line was silently exempt from the one rule that would have caught it.
            'NotAKey' => self::OWNER,
        ]);

        $this->assertSame([$captured], $reported);
    }
}
