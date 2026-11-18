<?php

declare(strict_types=1);

namespace Erpify\Documents\Document\Infrastructure\Messenger\Maintenance;

use Erpify\Documents\Document\Application\Erasure\ErasureLedger;
use Erpify\Documents\Document\Application\Erasure\ErasureOverrideSwitch;
use Erpify\Documents\Document\Application\Erasure\ErasureProgress;
use Erpify\Documents\Document\Application\Erasure\LedgerEntry;
use Erpify\Documents\Document\Application\Erasure\LedgerUnreadable;
use Erpify\Documents\Document\Application\Erasure\ReplayErasure;
use Erpify\Documents\Document\Application\Erasure\StandingOverride;
use Erpify\Documents\Document\Application\Upload\SweepBudget;
use Erpify\Documents\Document\Domain\Erasure\ErasureLimits;
use Erpify\Documents\Document\Domain\Erasure\ErasureSystemReason;
use Erpify\Shared\Clock\Domain\SystemClock;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Throwable;

/**
 * The per-scope completion sweep: lists the ledger and finishes every erasure whose entry landed and whose transaction
 * did not, through {@see ReplayErasure} with the reason `ledger-completion` and no actor (the ledger holds scope ids
 * only, so who authorised it is not recoverable by design). One of exactly two classes allowed to hold that service;
 * `ReplayErasureCallersGateTest` holds the set.
 *
 * **It never steals a live erasure.** An entry younger than {@see ErasureLimits::COMPLETION_MIN_AGE_SECONDS} is left
 * alone, judged from the entry's own timestamp, which restic stamps on the WRITING host (this application's) — the
 * margin carries clock drift between the writer and the sweep, and a worker that is slow rather than dead — so a
 * request still inside its transaction records its own operator as the actor.
 *
 * **When the ledger cannot be read it says so and does nothing else**: one `error` line per tick. That is the alarm,
 * and the fail-closed half of the design lives in `restore.prod`, not here.
 *
 * **It clears nothing.** While any ledger override stands it writes ONE `error` line per tick (the count and the age of
 * the oldest, by the database clock), so a standing 410 on the three read routes is never silent; clearing is
 * `documents:erasure:clear-override`, which a person runs, because the preconditions that command checks are necessary
 * and never sufficient.
 *
 * @SuppressWarnings("PHPMD.CouplingBetweenObjects") the ledger, the progress read, the replay and the override
 */
#[AsMessageHandler]
final readonly class CompleteInterruptedErasuresHandler
{
    private const int PER_TICK = 100;

    public function __construct(
        private ErasureLedger $ledger,
        private ErasureProgress $progress,
        private ReplayErasure $replayErasure,
        private ErasureOverrideSwitch $overrideSwitch,
        #[Autowire(service: 'monolog.logger.observability')]
        private LoggerInterface $logger,
    ) {
    }

    public function __invoke(CompleteInterruptedErasuresMessage $message): void
    {
        unset($message);

        $this->reportStandingOverride();

        try {
            $entries = $this->ledger->entries();
        } catch (LedgerUnreadable) {
            $this->logger->error(
                'The erasure ledger cannot be read in full; interrupted erasures are not being completed.',
            );

            return;
        }

        $cutoff = SystemClock::now()->modify('-' . ErasureLimits::COMPLETION_MIN_AGE_SECONDS . ' seconds');
        $mature = \array_values(\array_filter(
            $entries,
            static fn (LedgerEntry $entry): bool => $entry->recordedAt <= $cutoff,
        ));

        $this->complete(\array_map(static fn (LedgerEntry $entry): string => $entry->scopeId, $mature));
    }

    private function reportStandingOverride(): void
    {
        $standing = $this->overrideSwitch->standing();

        if (!$standing instanceof StandingOverride) {
            return;
        }

        $this->logger->error('The erasure ledger override is still set; Documents reads answer gone.', [
            'count' => $standing->count,
            'oldestAgeSeconds' => $standing->oldestAgeSeconds,
        ]);
    }

    /**
     * @param list<string> $scopeIds
     */
    private function complete(array $scopeIds): void
    {
        $unfinished = $this->progress->unfinished($scopeIds);
        $budget = new SweepBudget(self::PER_TICK);

        foreach ($unfinished as $scopeId) {
            if ($budget->exhausted()) {
                break;
            }

            try {
                $this->replayErasure->replay($scopeId, ErasureSystemReason::LedgerCompletion);
                $budget->finished();
            } catch (Throwable $throwable) {
                $this->logger->error('An interrupted erasure could not be completed; the batch goes on.', [
                    'scopeId' => $scopeId,
                    'exceptionClass' => $throwable::class,
                ]);
                $budget->failed();
            }
        }

        if ($budget->stoppedOnFailures()) {
            $this->logger->error('The erasure completion sweep stopped after consecutive failures.', [
                'failures' => SweepBudget::MAX_CONSECUTIVE_FAILURES,
            ]);
        }
    }
}
