<?php

declare(strict_types=1);

namespace Erpify\Documents\Document\Application\Erasure;

use Erpify\Documents\Document\Application\Derivative\DocumentDerivativeOrigin;
use Erpify\Documents\Document\Application\Upload\SweepBudget;
use Erpify\Documents\Document\Domain\Erasure\DocumentErasures;
use Erpify\Documents\Document\Domain\Erasure\ErasureLimits;
use Erpify\Shared\Images\Application\ReleaseDerivatives;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Ends the derivatives of erased documents (CAP-5). This context decides when a derivative dies; the images module only
 * queues the deletion of what still derives from the document, re-read on every call (ADR D8).
 *
 * It runs after the erasure committed, never inside it: byte removal is not a side effect of the owner's transaction
 * (invariant 4), and the erasure transaction has a statement budget of its own. A row's marker is set only by a call
 * that found nothing left to release — a confirmed absence — so a request lost to a dead letter is queued again on the
 * next tick rather than recorded as done.
 */
final readonly class ReleaseErasedDerivatives
{
    private const int ALARM_SCAN_LIMIT = 1_000;

    private const int IDS_PER_LINE = 20;

    public function __construct(
        private DocumentErasures $erasures,
        private ReleaseDerivatives $releaseDerivatives,
        private LoggerInterface $logger,
    ) {
    }

    /**
     * @return int how many rows this run confirmed released
     */
    public function run(int $limit): int
    {
        $budget = new SweepBudget($limit);
        $after = null;

        while (!$budget->exhausted()) {
            $page = $this->erasures->awaitingDerivativeRelease($limit, $after);

            foreach ($page as $documentId) {
                $after = $documentId;
                $this->releaseWithin($budget, $documentId);

                if ($budget->exhausted()) {
                    break;
                }
            }

            if (\count($page) < $limit) {
                break;
            }
        }

        if ($budget->stoppedOnFailures()) {
            $this->logger->error('The derivative release sweep stopped after consecutive failures.', [
                'failures' => SweepBudget::MAX_CONSECUTIVE_FAILURES,
                'released' => $budget->finishedCount(),
            ]);
        }

        $this->alarmOnOverdueDerivatives();

        return $budget->finishedCount();
    }

    private function releaseWithin(SweepBudget $budget, string $documentId): void
    {
        try {
            $this->releaseOne($documentId) ? $budget->finished() : $budget->passed();
        } catch (Throwable $throwable) {
            $this->logger->error('The derivatives of an erased document could not be released; the batch goes on.', [
                'documentId' => $documentId,
                'exceptionClass' => $throwable::class,
            ]);
            $budget->failed();
        }
    }

    private function releaseOne(string $documentId): bool
    {
        if (0 !== $this->releaseDerivatives->of(DocumentDerivativeOrigin::of($documentId))) {
            return false;
        }

        return $this->erasures->markDerivativesReleased($documentId);
    }

    private function alarmOnOverdueDerivatives(): void
    {
        $overdue = $this->erasures->derivativesOverdue(
            ErasureLimits::DERIVATIVE_ALARM_SECONDS,
            self::ALARM_SCAN_LIMIT,
        );

        if ([] === $overdue) {
            return;
        }

        $this->logger->error('Derivatives of erased documents are older than the bound.', [
            'count' => \count($overdue),
            'boundSeconds' => ErasureLimits::DERIVATIVE_ALARM_SECONDS,
            'documentIds' => \array_slice($overdue, 0, self::IDS_PER_LINE),
        ]);
    }
}
