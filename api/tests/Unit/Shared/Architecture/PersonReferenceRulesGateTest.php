<?php

declare(strict_types=1);

namespace Erpify\Tests\Unit\Shared\Architecture;

use Erpify\Tests\Support\PersonReferences;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * Falsifiability of the rules {@see PersonReferenceGateTest} asserts over the real tree: it drives
 * {@see PersonReferences} across a fixture tree and over hand-built inputs, so each check is shown to go red
 * for the reason claimed rather than being taken on trust.
 *
 * The two halves matter for different reasons. The fixture registries pin the COMPLETENESS direction — a
 * column with no line — which is the one that separates a detector from a document: a gate verifying only
 * what is declared passes dressed as one. The hand-built wiring inputs pin the half of the rule that decides
 * whether "declared a person, nobody erases it" fails; without it, `person :: <any existing file>` is enough
 * and the registry becomes prose again.
 *
 * Split from its sibling because one class carrying both would exceed the public-method ceiling, and the
 * make target selects them together through a common prefix rather than naming either.
 *
 * @internal
 */
#[CoversNothing]
final class PersonReferenceRulesGateTest extends TestCase
{
    private const string FIXTURES = __DIR__ . '/Fixture/PersonReference';

    private const string FIXTURE_NAMESPACE = 'Erpify\Tests\Unit\Shared\Architecture\Fixture\PersonReference\\';

    private const string SESSION_REFERENCE = \Erpify\Iam\Session\Domain\Entity\Session::class . '::$userId';

    #[Test]
    public function theGateRejectsAColumnWithNoLine(): void
    {
        $references = $this->overFixtures('policy.incomplete');
        $unclassified = \array_diff($references->universe(), \array_keys($references->classification()));

        $this->assertContains(
            self::FIXTURE_NAMESPACE . 'PersonReferenceFixtureEntity::$subjectId',
            $unclassified,
            'A persisted identifier column with no line passed the completeness check — the direction that '
            . 'distinguishes a detector from a document.',
        );
    }

    #[Test]
    public function theGateRejectsALineNoColumnBacks(): void
    {
        $references = $this->overFixtures('policy.stale');
        $stale = \array_diff(\array_keys($references->classification()), $references->universe());

        $this->assertContains(
            self::FIXTURE_NAMESPACE . 'PersonReferenceFixtureEntity::$retiredId',
            $stale,
            'A line matching no column passed the staleness check.',
        );
    }

    #[Test]
    public function theCleanFixtureTwinPasses(): void
    {
        $references = $this->overFixtures('policy.complete');

        $this->assertSame([], \array_values(\array_diff(
            $references->universe(),
            \array_keys($references->classification()),
        )), 'The clean fixture twin failed the completeness check — the dirty-fixture reds prove nothing.');
        $this->assertSame([], \array_values(\array_diff(
            \array_keys($references->classification()),
            $references->universe(),
        )), 'The clean fixture twin failed the staleness check.');
    }

    #[Test]
    public function theFixtureTreeIsActuallyScanned(): void
    {
        // If the fixture FQCNs did not reconstruct, class_exists() would be false for all of them and the
        // three fixture tests above would pass while scanning nothing at all. Asserting the exact list also
        // pins every derivation rule at once: `$label` is a column and must stay out; `$custodian`,
        // `$delegate` and `$deputy` cover the annotated and plain to-one forms, so keying on the join column
        // or reading only `ManyToOne` drops one of them; and `$inheritedSubjectId` reaches the universe only
        // through the child, so removing the parent-property fold removes it. Each rule has a member here
        // whose absence is a failure — which is the difference between a rule that holds and one nothing runs.
        $this->assertSame([
            self::FIXTURE_NAMESPACE . 'InheritedColumnFixtureEntity::$inheritedSubjectId',
            self::FIXTURE_NAMESPACE . 'PersonReferenceFixtureEntity::$custodian',
            self::FIXTURE_NAMESPACE . 'PersonReferenceFixtureEntity::$delegate',
            self::FIXTURE_NAMESPACE . 'PersonReferenceFixtureEntity::$deputy',
            self::FIXTURE_NAMESPACE . 'PersonReferenceFixtureEntity::$subjectId',
            self::FIXTURE_NAMESPACE . 'PersonReferenceFixtureEntity::$tenantId',
        ], $this->overFixtures('policy.complete')->universe(), 'The fixture sweep derived the wrong columns.');
    }

    #[Test]
    public function theWiringCheckAcceptsAnOwnerThatDeletes(): void
    {
        $this->assertNull($this->references()->erasureDefectIn(
            self::SESSION_REFERENCE,
            'src/Iam/Session/Application/PurgeUserSessions.php',
        ), 'The wiring check rejected an owner that holds the repository and deletes through it.');
    }

    #[Test]
    public function theWiringCheckRejectsAnOwnerThatHoldsTheCollaboratorButNeverDeletes(): void
    {
        // RevokeAllSessions holds the very same SessionRepository and flips a status through it. Declaring it
        // as the erasure owner has to fail: a revoked row keeps its `user_id`.
        $defect = $this->references()->erasureDefectIn(
            self::SESSION_REFERENCE,
            'src/Iam/Session/Application/RevokeAllSessions.php',
        );

        $this->assertNotNull($defect, 'An owner that never deletes was accepted as erasing the reference.');
        $this->assertStringContainsString('never calls a deletion', $defect);
    }

    #[Test]
    public function theWiringCheckRejectsAnOwnerThatDoesNotSpeakTheEntity(): void
    {
        $defect = $this->references()->erasureDefectIn(
            \Erpify\Backoffice\Bank\Domain\Entity\Bank::class . '::$id',
            'src/Iam/Session/Application/PurgeUserSessions.php',
        );

        $this->assertNotNull($defect, 'An owner with no collaborator for the entity was accepted.');
        $this->assertStringContainsString('holds no collaborator naming "Bank"', $defect);
    }

    #[Test]
    public function theWiringCheckRejectsADirectoryAndATraversal(): void
    {
        $references = $this->references();

        // is_file() is what stops the first: file_exists() is true for a directory, so a bare directory
        // would silence the check with nothing written at all.
        $this->assertNotNull(
            $references->erasureDefectIn(self::SESSION_REFERENCE, 'src/Iam/Session/Application'),
            'A directory was accepted as the file that erases a person reference.',
        );
        $this->assertNotNull(
            $references->erasureDefectIn(self::SESSION_REFERENCE, 'src/../../etc/passwd'),
            'A path escaping the repository was accepted as an erasure owner.',
        );
    }

    #[Test]
    public function theRegistryParserRejectsRatherThanDegrades(): void
    {
        // A duplicate would let the later line silently shadow the earlier, and `person` with no owner is not
        // a spelling at all — an unowned person reference is the violation itself. Degrading either to
        // non-person is the one outcome that must be impossible, because the wiring check skips non-person.
        $this->assertStringContainsString(
            'Duplicate registry line',
            $this->parseFailureOf('policy.duplicate'),
        );
        $this->assertStringContainsString(
            'Unrecognised classification',
            $this->parseFailureOf('policy.unrecognised'),
        );
    }

    #[Test]
    public function theDeclarationSweepReachesClassesThatBackNoTable(): void
    {
        // An abstract carrier holds no columns, so its declaration can never reach the registry. The sweep
        // must still see it, or an erasure obligation written where nothing reads it passes in silence.
        $stranded = self::FIXTURE_NAMESPACE . 'AbstractFixtureDeclarationCarrier::$strandedUserId';
        $references = $this->overFixtures('policy.complete');

        $this->assertArrayHasKey($stranded, $references->declaredOwners());
        $this->assertNotContains($stranded, $references->universe());
        $this->assertContains($stranded, $references->strandedDeclarations());

        // The abstract parent of a mapped column is the opposite case, and telling them apart is the whole
        // point: the property is declared once on the parent, so it cannot be annotated per child, and a
        // check reading the key literally would leave that shape with no way to declare at all.
        $this->assertNotContains(
            self::FIXTURE_NAMESPACE . 'AbstractFixtureColumnCarrier::$inheritedSubjectId',
            $references->strandedDeclarations(),
            'A declaration on the parent of a column a concrete entity inherits was reported as stranded, '
            . 'which leaves that mapping no green state at all.',
        );
    }

    private function parseFailureOf(string $registry): string
    {
        try {
            $this->overFixtures($registry)->classification();
        } catch (RuntimeException $runtimeException) {
            return $runtimeException->getMessage();
        }

        $this->fail(\sprintf('%s parsed without complaint; the parser degraded it instead of rejecting.', $registry));
    }

    private function references(): PersonReferences
    {
        return PersonReferences::fromGateLocation(__DIR__);
    }

    private function overFixtures(string $registry): PersonReferences
    {
        return new PersonReferences(
            \dirname(__DIR__, 4),
            self::FIXTURES,
            self::FIXTURE_NAMESPACE,
            self::FIXTURES . '/' . $registry,
        );
    }
}
