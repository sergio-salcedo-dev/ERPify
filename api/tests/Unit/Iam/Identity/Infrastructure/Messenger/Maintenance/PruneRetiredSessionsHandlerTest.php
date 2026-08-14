<?php

declare(strict_types=1);

namespace Erpify\Tests\Unit\Iam\Identity\Infrastructure\Messenger\Maintenance;

use DateTimeImmutable;
use Erpify\Iam\Identity\Infrastructure\Messenger\Maintenance\PruneRetiredSessionsHandler;
use Erpify\Iam\Identity\Infrastructure\Messenger\Maintenance\PruneRetiredSessionsMessage;
use Erpify\Iam\Session\Application\PruneRetiredSessions;
use Erpify\Shared\Clock\Domain\SystemClock;
use Erpify\Tests\Unit\Iam\Session\Application\FixedClock;
use Erpify\Tests\Unit\Iam\Session\Application\InMemorySessionRepository;
use Override;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * The handler's whole contract is that a tick reaches the sweep exactly once — the windows and the row
 * selection are the use case's, pinned by its own cases. What is worth a red here is a tick that runs nothing,
 * which is the shape a scheduled control fails in silently.
 *
 * @internal
 */
#[CoversClass(PruneRetiredSessionsHandler::class)]
final class PruneRetiredSessionsHandlerTest extends TestCase
{
    #[Override]
    protected function tearDown(): void
    {
        SystemClock::reset();
    }

    public function testATickRunsTheRetentionSweepExactlyOnce(): void
    {
        $sessions = new InMemorySessionRepository();
        $handler = new PruneRetiredSessionsHandler(
            new PruneRetiredSessions($sessions, new FixedClock(new DateTimeImmutable('2026-07-10T12:00:00+00:00'))),
        );

        $handler(new PruneRetiredSessionsMessage());

        $this->assertCount(1, $sessions->deleteRetiredCalls);
    }

    public function testTheSweepIsAskedForBothRetentionWindowsAndNotOneOfThem(): void
    {
        $now = new DateTimeImmutable('2026-07-10T12:00:00+00:00');
        $sessions = new InMemorySessionRepository();
        $handler = new PruneRetiredSessionsHandler(new PruneRetiredSessions($sessions, new FixedClock($now)));

        $handler(new PruneRetiredSessionsMessage());

        // Distinct thresholds, each on its own axis: collapsing them into one would silently retire revoked
        // rows on the 90-day clock or lapsed ones on the 30-day clock, with every count still correct.
        //
        // The instants are written out rather than derived with the same `modify()` calls the use case makes,
        // which would restate the implementation instead of pinning it; and they are compared as formatted
        // strings because the whole recorded list is asserted at once, where identity — not value — is what
        // an object comparison would test.
        $this->assertSame(
            [['revokedBefore' => '2026-06-10T12:00:00+00:00', 'expiredBefore' => '2026-04-11T12:00:00+00:00']],
            \array_map(
                static fn (array $call): array => [
                    'revokedBefore' => $call['revokedBefore']->format(DATE_ATOM),
                    'expiredBefore' => $call['expiredBefore']->format(DATE_ATOM),
                ],
                $sessions->deleteRetiredCalls,
            ),
        );
    }
}
