<?php

declare(strict_types=1);

namespace Erpify\Documents\Document\Application\Erasure;

use Closure;
use Erpify\Documents\Document\Application\Derivative\DocumentDerivativeOrigin;
use Erpify\Documents\Document\Application\Upload\SweepBudget;
use Erpify\Documents\Document\Domain\Erasure\DocumentDerivativeReleases;
use Erpify\Documents\Document\Domain\Erasure\ErasureLimits;
use Erpify\Shared\Images\Application\DerivativeOrigins;
use Erpify\Shared\Images\Application\ReleaseDerivatives;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Ends the derivatives of erased documents. This context decides when a derivative dies; the images module only
 * queues the deletion of what still derives from the document, re-read on every call (ADR D8).
 *
 * It runs after the erasure committed, never inside it: byte removal is not a side effect of the owner's transaction
 * (invariant 4), and the erasure transaction has a statement budget of its own. A row's marker is set only by a call
 * that found nothing left to release — a confirmed absence — so a request lost to a dead letter is queued again once
 * the retry interval has passed rather than recorded as done. That interval bounds what a deletion that keeps failing
 * produces, and keeps a row still pending from taking a later one's place in a tick.
 */
final readonly class ReleaseErasedDerivatives
{
    private const int ALARM_SCAN_LIMIT = 1_000;

    private const int IDS_PER_LINE = 20;

    public function __construct(
        private DocumentDerivativeReleases $releases,
        private ReleaseDerivatives $releaseDerivatives,
        private DerivativeOrigins $derivativeOrigins,
        private LoggerInterface $logger,
    ) {
    }

    /**
     * Two passes, each under its own budget: rows due a request get one, and rows whose request is still recent are
     * only asked whether anything is left, so a release that already happened is confirmed on the next tick instead
     * of waiting out the interval, and neither pass can take the other's place in a tick.
     *
     * @return int how many rows this run confirmed released
     */
    public function run(int $limit): int
    {
        $released = $this->sweep(
            $limit,
            fn (?string $after): array => $this->releases->awaitingDerivativeRelease(
                ErasureLimits::DERIVATIVE_REREQUEST_SECONDS,
                $limit,
                $after,
            ),
            $this->releaseOne(...),
        );
        $confirmed = $this->sweep(
            $limit,
            fn (?string $after): array => $this->releases->awaitingConfirmation(
                ErasureLimits::DERIVATIVE_REREQUEST_SECONDS,
                $limit,
                $after,
            ),
            $this->confirmOne(...),
        );

        $this->alarmOnOverdueDerivatives();

        return $released + $confirmed;
    }

    /**
     * @param Closure(?string): list<string> $nextPage
     * @param Closure(string): bool          $handle   true when the row is confirmed released
     */
    private function sweep(int $limit, Closure $nextPage, Closure $handle): int
    {
        $budget = new SweepBudget($limit);
        $after = null;

        while (!$budget->exhausted()) {
            $page = $nextPage($after);

            foreach ($page as $documentId) {
                $after = $documentId;
                $this->handleWithin($budget, $handle, $documentId);

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

        return $budget->finishedCount();
    }

    /**
     * @param Closure(string): bool $handle
     */
    private function handleWithin(SweepBudget $budget, Closure $handle, string $documentId): void
    {
        try {
            $handle($documentId) ? $budget->finished() : $budget->passed();
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
            $this->releases->markDerivativesRequested($documentId);

            return false;
        }

        return $this->releases->markDerivativesReleased($documentId);
    }

    private function confirmOne(string $documentId): bool
    {
        if ($this->derivativeOrigins->holdsAny(DocumentDerivativeOrigin::of($documentId))) {
            return false;
        }

        return $this->releases->markDerivativesReleased($documentId);
    }

    private function alarmOnOverdueDerivatives(): void
    {
        $overdue = $this->releases->derivativesOverdue(
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
