<?php

declare(strict_types=1);

namespace Erpify\Tests\Unit\Iam\Identity\Application;

use Erpify\Iam\Identity\Application\PersonReferenceFinding;
use Erpify\Iam\Identity\Application\ReconcileErasedSubjectReferences;
use Erpify\Iam\Session\Domain\Entity\Session;
use Erpify\Organization\Membership\Domain\Entity\Membership;
use Erpify\Tests\Unit\Iam\Identity\Domain\Entity\Mother\UserMother;
use Erpify\Tests\Unit\Shared\Audit\Infrastructure\Double\FixedPersonResourceReferences;
use Erpify\Tests\Unit\Shared\Privacy\Infrastructure\Double\FixedPersonReferenceSource;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * The detective control's whole value is which rows it reports and WHERE they are. Three failure modes
 * matter: a missed erasure that goes unreported (the control is useless), a correct erasure reported as a
 * divergence (the control is noise an operator learns to ignore, which is worse than none), and a verdict
 * that merges its places into one list — under which a check seeding a single place and asserting non-empty
 * passes while every other source sits unwired.
 *
 * @internal
 */
#[CoversClass(ReconcileErasedSubjectReferences::class)]
final class ReconcileErasedSubjectReferencesTest extends TestCase
{
    private const string AUDIT_AXIS = 'audit_log.resource_id';

    private const string MEMBERSHIP_AXIS = Membership::class . '::$userId';

    private const string SESSION_AXIS = Session::class . '::$userId';

    private const string GONE_ID = '0190f400-0000-7000-8000-0000000000c1';

    private const string OTHER_GONE_ID = '0190f400-0000-7000-8000-0000000000c2';

    #[Test]
    public function itReportsAnIdentityTheAuditTrailStillNamesAfterItWasErased(): void
    {
        $verdict = $this->reconciler([self::GONE_ID], [])->unreconciledReferences();

        $this->assertSame(
            [self::AUDIT_AXIS => [self::GONE_ID]],
            $this->reported($verdict->findings()),
        );
    }

    #[Test]
    public function itReportsAnIdentityAnOwningContextStillHolds(): void
    {
        $verdict = $this->reconciler([], [
            self::MEMBERSHIP_AXIS => [self::GONE_ID],
        ])->unreconciledReferences();

        $this->assertSame(
            [self::MEMBERSHIP_AXIS => [self::GONE_ID]],
            $this->reported($verdict->findings()),
        );
    }

    #[Test]
    public function itAttributesEachDivergenceToItsOwnPlaceRatherThanMergingThem(): void
    {
        // The assertion AC3 exists for: with one merged list, a fixture seeding only the audit axis would
        // satisfy "something was reported" while the membership source was never wired at all.
        $verdict = $this->reconciler([self::GONE_ID], [
            self::MEMBERSHIP_AXIS => [self::OTHER_GONE_ID],
            self::SESSION_AXIS => [],
        ])->unreconciledReferences();

        // Sorted by axis key, not by the order the places were asked: the asking order is the container's
        // filesystem walk and may reshuffle across a redeploy, which would make a diffing alert fire on
        // nothing. `Erpify\…` sorts before `audit_log.…`.
        $this->assertSame([
            self::MEMBERSHIP_AXIS => [self::OTHER_GONE_ID],
            self::AUDIT_AXIS => [self::GONE_ID],
        ], $this->reported($verdict->findings()));
        $this->assertSame(2, $verdict->total());
    }

    #[Test]
    public function itCountsEveryPlaceItAskedIncludingTheOnesThatReconcile(): void
    {
        // An axis that reports nothing still has to be counted: "checked and clean" and "never wired" are
        // the two states this control cannot be allowed to spell the same way.
        $verdict = $this->reconciler([], [
            self::MEMBERSHIP_AXIS => [],
            self::SESSION_AXIS => [],
        ])->unreconciledReferences();

        $this->assertTrue($verdict->isEmpty());
        $this->assertSame(3, $verdict->axesChecked());
    }

    #[Test]
    public function itIgnoresAReferenceToAnIdentityThatIsStillLive(): void
    {
        // Named in the trail, held by a membership, and present in identity_user: an ordinary reference in
        // both places, not a divergence.
        $reconciler = new ReconcileErasedSubjectReferences(
            new FixedPersonResourceReferences([UserMother::DEFAULT_ID]),
            [new FixedPersonReferenceSource(self::MEMBERSHIP_AXIS, [UserMother::DEFAULT_ID])],
            new InMemoryLiveIdentityDirectory([UserMother::DEFAULT_ID]),
        );

        $this->assertTrue($reconciler->unreconciledReferences()->isEmpty());
    }

    #[Test]
    public function itNeverSeesAnAlreadyAnonymisedAuditReference(): void
    {
        // The audit port filters `resource_erased = TRUE` away, so a correctly erased subject never reaches
        // the resolver. Without that filter its pseudonym would resolve to no identity and be reported —
        // every successful erasure would look like a failure. The other places have no equivalent: there the
        // row is deleted, so an empty list is what a completed erasure leaves behind.
        $verdict = $this->reconciler([], [self::MEMBERSHIP_AXIS => []])->unreconciledReferences();

        $this->assertSame([], $verdict->findings());
    }

    /**
     * @param list<string>                $namedInTheTrail
     * @param array<string, list<string>> $heldByOwningContexts axis key => the ids that place holds
     */
    private function reconciler(array $namedInTheTrail, array $heldByOwningContexts): ReconcileErasedSubjectReferences
    {
        $sources = [];

        foreach ($heldByOwningContexts as $axisKey => $ids) {
            $sources[] = new FixedPersonReferenceSource($axisKey, $ids);
        }

        // An empty directory resolves every referenced id to a gone identity, so what the doubles hold is
        // exactly the set of divergences.
        return new ReconcileErasedSubjectReferences(
            new FixedPersonResourceReferences($namedInTheTrail),
            $sources,
            new InMemoryLiveIdentityDirectory(),
        );
    }

    /**
     * @param list<PersonReferenceFinding> $findings
     *
     * @return array<string, list<string>>
     */
    private function reported(array $findings): array
    {
        $reported = [];

        foreach ($findings as $finding) {
            $reported[$finding->axis->key()] = $finding->subjectIds;
        }

        return $reported;
    }
}
