<?php

declare(strict_types=1);

namespace Erpify\Tests\Unit\Gate;

use Erpify\Backoffice\Bank\Domain\Entity\Bank;
use Erpify\Iam\Identity\Domain\Entity\PasswordResetToken;
use Erpify\Iam\Identity\Domain\Entity\User;
use Erpify\Iam\Session\Domain\Entity\Session;
use Erpify\Tests\Support\PersonReferenceSourceCoverage;
use Erpify\Tests\Support\PersonReferenceSources;
use Erpify\Tests\Unit\Gate\Fixture\PersonReference\PersonReferenceFixtureEntity;
use Erpify\Tests\Unit\Gate\Fixture\PersonReferenceSource\Covered\CoveredSourceFixture;
use Erpify\Tests\Unit\Gate\Fixture\PersonReferenceSource\Duplicated\FirstDuplicateSourceFixture;
use Erpify\Tests\Unit\Gate\Fixture\PersonReferenceSource\Duplicated\SecondDuplicateSourceFixture;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * Falsifiability of the rules {@see PersonReferenceSourceGateTest} asserts over the real tree: it drives
 * {@see PersonReferenceSources} across fixture trees and {@see PersonReferenceSourceCoverage} over
 * hand-built inputs, so each check is shown to go red for the reason claimed rather than being taken on
 * trust.
 *
 * The two halves pin different things. The fixture trees pin the DERIVATION — that a concrete source is
 * seen, an abstract carrier is not, a collision is visible, and an axis that needs its constructor is
 * refused. The hand-built inputs pin the COMPARISON, including the exemption: a rule that reported the
 * subject's own primary key as an uncovered reference would demand a source for the identity table itself,
 * and one that exempted by owner file instead would drop `PasswordResetToken::$userId` — a quarter of the
 * real coverage — while staying compile-clean.
 *
 * @internal
 */
#[CoversNothing]
final class PersonReferenceSourceRulesGateTest extends TestCase
{
    private const string FIXTURES = __DIR__ . '/Fixture/PersonReferenceSource';

    private const string FIXTURE_NAMESPACE = 'Erpify\Tests\Unit\Gate\Fixture\PersonReferenceSource\\';

    private const string COVERED_AXIS = PersonReferenceFixtureEntity::class . '::$subjectId';

    private const string OWNER = 'src/Iam/Session/Application/PurgeUserSessions.php';

    private const string IDENTITY_OWNER = 'src/Iam/Identity/Application/EraseIdentitySubject.php';

    private const string RESET_TOKEN_REFERENCE = PasswordResetToken::class . '::$userId';

    #[Test]
    public function theScanSeesAConcreteSourceAndSkipsAnAbstractCarrier(): void
    {
        // The exact map, not merely "contains": the abstract carrier declares an axis of its own, so a sweep
        // that failed to skip it would report a second, uncollectable axis as covered.
        $this->assertSame(
            [self::COVERED_AXIS => [CoveredSourceFixture::class]],
            $this->overFixtures('Covered')->declaredAxes(),
        );
    }

    #[Test]
    public function theScanSkipsAnEnumCarryingTheContract(): void
    {
        // Its own guard, not a corollary of the abstract one: an enum is concrete, so only the enum check
        // keeps it out. Without it the scan reaches reflection's refusal to instantiate an enum, an `Error`
        // the axis read catches and relabels "cannot be read without constructing it" — a message about a
        // different mistake entirely.
        $this->assertSame([], $this->overFixtures('BackedByEnum')->declaredAxes());
    }

    #[Test]
    public function theScanReportsAnAxisTwoSourcesClaim(): void
    {
        $declared = $this->overFixtures('Duplicated')->declaredAxes();

        $this->assertSame(
            [self::COVERED_AXIS => [FirstDuplicateSourceFixture::class, SecondDuplicateSourceFixture::class]],
            $declared,
        );
        $this->assertSame(
            [self::COVERED_AXIS],
            \array_keys(PersonReferenceSourceCoverage::duplicatedIn($declared)),
            'Two sources on one axis passed the collision check.',
        );
    }

    #[Test]
    public function theScanRefusesAnAxisThatCannotBeReadWithoutTheConstructor(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/cannot be read without constructing it/');

        $this->overFixtures('ConstructorBound')->declaredAxes();
    }

    #[Test]
    public function theComparisonReportsAPersonReferenceNoSourceCovers(): void
    {
        $uncovered = PersonReferenceSourceCoverage::uncoveredIn(
            [Session::class . '::$userId' => self::OWNER],
            [],
        );

        $this->assertSame([Session::class . '::$userId'], $uncovered);
    }

    #[Test]
    public function theComparisonReportsASourceTheRegistryDoesNotBack(): void
    {
        $unbacked = PersonReferenceSourceCoverage::unbackedIn(
            [Session::class . '::$userId' => self::OWNER],
            [Session::class . '::$userId' => ['A'], 'Typo\Entity::$userId' => ['B']],
        );

        $this->assertSame(['Typo\Entity::$userId'], $unbacked);
    }

    #[Test]
    public function theComparisonExemptsTheSubjectsOwnPrimaryKeyAndNothingElse(): void
    {
        // Every direction of the exemption in one input, because each is a different way to get it wrong:
        //   - `User::$id` is the person's own row, so no source owes it coverage;
        //   - the reset token names the very same owner file and IS a reference, so a by-owner spelling of
        //     the exemption would drop it here;
        //   - `Bank::$id` is a PRIMARY KEY that is not the subject's. Reading `#[ORM\Id]` alone — "is a
        //     primary key" rather than "is the person's key" — exempts it, and with it every future person
        //     id that happens to key its own row: a composite join key, a one-to-one extension table. That
        //     column would then need neither a source nor an attribute, compile-clean, which is the exact
        //     claim this gate makes impossible.
        $uncovered = PersonReferenceSourceCoverage::uncoveredIn(
            [
                User::class . '::$id' => self::IDENTITY_OWNER,
                self::RESET_TOKEN_REFERENCE => self::IDENTITY_OWNER,
                Bank::class . '::$id' => self::IDENTITY_OWNER,
                Session::class . '::$organizationId' => null,
            ],
            [],
        );

        $this->assertSame([self::RESET_TOKEN_REFERENCE, Bank::class . '::$id'], $uncovered);
    }

    #[Test]
    public function theCleanPairingPasses(): void
    {
        // The dirty reds above prove nothing unless the matching clean input is green.
        $classification = [Session::class . '::$userId' => self::OWNER];
        $declaredAxes = [Session::class . '::$userId' => ['A']];

        $this->assertSame([], PersonReferenceSourceCoverage::uncoveredIn($classification, $declaredAxes));
        $this->assertSame([], PersonReferenceSourceCoverage::unbackedIn($classification, $declaredAxes));
        $this->assertSame([], PersonReferenceSourceCoverage::duplicatedIn($declaredAxes));
    }

    private function overFixtures(string $scenario): PersonReferenceSources
    {
        return new PersonReferenceSources(
            self::FIXTURES . '/' . $scenario,
            self::FIXTURE_NAMESPACE . $scenario . '\\',
        );
    }
}
