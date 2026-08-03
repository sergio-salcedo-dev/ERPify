<?php

declare(strict_types=1);

namespace Erpify\Tests\Unit\Iam\Identity\Infrastructure\Messenger\Maintenance;

use Erpify\Iam\Identity\Application\PersonReferenceProbeFailed;
use Erpify\Iam\Identity\Application\ReconcileErasedSubjectReferences;
use Erpify\Iam\Identity\Domain\Repository\LiveIdentityDirectory;
use Erpify\Iam\Identity\Infrastructure\Messenger\Maintenance\ReconcilePersonReferencesHandler;
use Erpify\Iam\Identity\Infrastructure\Messenger\Maintenance\ReconcilePersonReferencesMessage;
use Erpify\Iam\Session\Domain\Entity\Session;
use Erpify\Organization\Membership\Domain\Entity\Membership;
use Erpify\Tests\Unit\Iam\Identity\Application\InMemoryLiveIdentityDirectory;
use Erpify\Tests\Unit\Shared\Audit\Infrastructure\Double\FixedPersonResourceReferences;
use Erpify\Tests\Unit\Shared\Privacy\Infrastructure\Double\FixedPersonReferenceSource;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * The alarm is the whole reason for scheduling this control, and it has three obligations: say nothing when
 * there is nothing to say, state the SIZE of a divergence without naming the people it is about, and keep a
 * broken probe distinguishable from a real finding.
 *
 * The second one is not a style preference. A control built to enforce that no person's id outlives its
 * erasure would, by writing those ids to stderr, put them in log storage under its own retention with no
 * declared owner of their erasure — the same violation, committed by the mechanism that exists to detect it.
 * The ids stay in the CLI, which an operator drives and whose output is ephemeral.
 *
 * The reconciler is built for real here rather than doubled: it is `final readonly`, and a fake standing in
 * for it would let this test pass against a verdict shape the production one cannot produce.
 *
 * @internal
 */
#[CoversClass(ReconcilePersonReferencesHandler::class)]
final class ReconcilePersonReferencesHandlerTest extends TestCase
{
    private const string MEMBERSHIP_AXIS = Membership::class . '::$userId';

    private const string SESSION_AXIS = Session::class . '::$userId';

    private const string GONE_ID = '0190f400-0000-7000-8000-0000000000c1';

    private const string OTHER_GONE_ID = '0190f400-0000-7000-8000-0000000000c2';

    #[Test]
    public function itStaysSilentWhenEveryReferenceReconciles(): void
    {
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects($this->never())->method('error');

        $this->handle($this->reconciler([], [self::MEMBERSHIP_AXIS => []]), $logger);
    }

    #[Test]
    public function itAlarmsAtErrorLevelSoProductionDoesNotDiscardIt(): void
    {
        // Prod routes Monolog through `fingers_crossed` with `action_level: error`, so a `warning` would be
        // buffered and dropped: the alarm would never fire in the only environment that matters.
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects($this->once())->method('error');

        $this->handle($this->reconciler([], [self::MEMBERSHIP_AXIS => [self::GONE_ID]]), $logger);
    }

    #[Test]
    public function itReportsCountsAndAxesAndNeverASubjectId(): void
    {
        $loggedMessage = '';
        $loggedContext = [];
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects($this->once())
            ->method('error')
            ->willReturnCallback(
                static function (string $message, array $context) use (&$loggedMessage, &$loggedContext): void {
                    $loggedMessage = $message;
                    $loggedContext = $context;
                },
            )
        ;

        $this->handle($this->reconciler([], [
            self::MEMBERSHIP_AXIS => [self::GONE_ID, self::OTHER_GONE_ID],
            self::SESSION_AXIS => [self::GONE_ID],
        ]), $logger);

        // The whole line, message and context together: an id would be as leaked in one as in the other.
        $serialised = \json_encode([$loggedMessage, $loggedContext], JSON_THROW_ON_ERROR);

        $this->assertStringNotContainsString(self::GONE_ID, $serialised);
        $this->assertStringNotContainsString(self::OTHER_GONE_ID, $serialised);
        $this->assertArrayHasKey('total', $loggedContext);
        $this->assertSame(3, $loggedContext['total']);
        // The audit axis is always asked, so three axes ran for two sources — the denominator an operator
        // reads as "this much of the obligation was actually checked".
        $this->assertArrayHasKey('axesChecked', $loggedContext);
        $this->assertSame(3, $loggedContext['axesChecked']);
        $this->assertArrayHasKey('divergentByAxis', $loggedContext);
        // Sorted by axis key, inherited from the verdict's own ordering: an alert that diffs this line has to
        // fire on a changed set and never on the container's tagged-iterator walk being reshuffled by a
        // redeploy. `Erpify\Iam\…` sorts before `Erpify\Organization\…`.
        $this->assertSame(
            [self::SESSION_AXIS => 1, self::MEMBERSHIP_AXIS => 2],
            $loggedContext['divergentByAxis'],
        );
    }

    #[Test]
    public function itLetsAProbeFailurePropagateInsteadOfLoggingItAsAFinding(): void
    {
        // A fault and a finding leave by different doors on purpose. An `error` line does not reach Sentry
        // (the Monolog bridge is deliberately unwired) while an exception out of a Messenger handler does —
        // so letting this one through is what puts an infrastructure fault in front of an engineer, and what
        // stops "the control ran and found nothing" from covering for "the control could not run".
        $identities = $this->createStub(LiveIdentityDirectory::class);
        $identities->method('existingIdsAmong')->willThrowException(new RuntimeException('connection lost'));

        $reconciler = new ReconcileErasedSubjectReferences(
            new FixedPersonResourceReferences([self::GONE_ID]),
            [],
            $identities,
        );
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects($this->never())->method('error');

        $this->expectException(PersonReferenceProbeFailed::class);

        $this->handle($reconciler, $logger);
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
        // exactly the set of divergences the alarm has to size.
        return new ReconcileErasedSubjectReferences(
            new FixedPersonResourceReferences($namedInTheTrail),
            $sources,
            new InMemoryLiveIdentityDirectory(),
        );
    }

    private function handle(ReconcileErasedSubjectReferences $reconciler, LoggerInterface $logger): void
    {
        (new ReconcilePersonReferencesHandler($reconciler, $logger))(new ReconcilePersonReferencesMessage());
    }
}
