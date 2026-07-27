<?php

declare(strict_types=1);

namespace Erpify\Tests\Unit\Iam\Identity\Application;

use Erpify\Iam\Identity\Application\ReconcileErasedSubjectReferences;
use Erpify\Tests\Unit\Iam\Identity\Domain\Entity\Mother\UserMother;
use Erpify\Tests\Unit\Shared\Audit\Infrastructure\Double\FixedPersonResourceReferences;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * The detective control's whole value is which rows it reports. Two failure modes matter symmetrically: a
 * missed erasure that goes unreported (the control is useless) and a correct erasure reported as a
 * divergence (the control is noise an operator learns to ignore, which is worse than none).
 *
 * @internal
 */
#[CoversClass(ReconcileErasedSubjectReferences::class)]
final class ReconcileErasedSubjectReferencesTest extends TestCase
{
    private const string GONE_ID = '0190f400-0000-7000-8000-0000000000c1';

    #[Test]
    public function itReportsAnIdentityTheTrailStillNamesAfterItWasErased(): void
    {
        $reconciler = new ReconcileErasedSubjectReferences(
            new FixedPersonResourceReferences([self::GONE_ID]),
            new InMemoryUserRepository(),
        );

        $this->assertSame([self::GONE_ID], $reconciler->unreconciledSubjectIds());
    }

    #[Test]
    public function itIgnoresAnIdentityThatIsStillLive(): void
    {
        // Named in the trail and present in identity_user: an ordinary reference, not a divergence.
        $reconciler = new ReconcileErasedSubjectReferences(
            new FixedPersonResourceReferences([UserMother::DEFAULT_ID]),
            new InMemoryUserRepository(UserMother::create()),
        );

        $this->assertSame([], $reconciler->unreconciledSubjectIds());
    }

    #[Test]
    public function itNeverSeesAnAlreadyAnonymisedReference(): void
    {
        // The port filters `resource_erased = TRUE` away, so a correctly erased subject never reaches the
        // resolver. Without that filter its pseudonym would resolve to no user and be reported — every
        // successful erasure would look like a failure.
        $reconciler = new ReconcileErasedSubjectReferences(
            new FixedPersonResourceReferences([]),
            new InMemoryUserRepository(),
        );

        $this->assertSame([], $reconciler->unreconciledSubjectIds());
    }
}
