<?php

declare(strict_types=1);

namespace Erpify\Tests\Unit\Iam\Session\Application;

use Erpify\Iam\Session\Application\SessionSubjectLock;
use Erpify\Iam\Session\Domain\SubjectAdmission;
use Override;

/**
 * {@see SessionSubjectLock} that answers a settable verdict and records each call — with what the session
 * repository had saved at that moment and whether a transaction was open — so a test can tell a lock taken inside
 * the unit of work and before any session write from one taken anywhere else.
 *
 * @internal
 */
final class RecordingSessionSubjectLock implements SessionSubjectLock
{
    /**
     * Per call: the user id, how many sessions had been saved, whether a transaction was open, and which of the
     * two holds was asked for — a login and a revocation take different strengths, so a test can tell them apart.
     *
     * @var list<array{string, int, bool, string}>
     */
    public array $calls = [];

    /** What {@see holdsLive()} answers: false plays an identity erased before the lock was granted. */
    public bool $live = true;

    /** What {@see holdForAdmission()} answers: anything but admissible plays a write that won the race. */
    public SubjectAdmission $admission = SubjectAdmission::Admissible;

    public function __construct(
        private readonly ?InMemorySessionRepository $sessions = null,
        private readonly ?TransactionSnapshottingManager $transactions = null,
    ) {
    }

    #[Override]
    public function holdsLive(string $userId): bool
    {
        $this->record($userId, 'holdsLive');

        return $this->live;
    }

    #[Override]
    public function holdForAdmission(string $userId): SubjectAdmission
    {
        $this->record($userId, 'holdForAdmission');

        return $this->admission;
    }

    /**
     * @return list<string>
     */
    public function methodsCalled(): array
    {
        return \array_column($this->calls, 3);
    }

    private function record(string $userId, string $method): void
    {
        $this->calls[] = [
            $userId,
            \count($this->sessions->saved ?? []),
            $this->transactions->open ?? false,
            $method,
        ];
    }
}
