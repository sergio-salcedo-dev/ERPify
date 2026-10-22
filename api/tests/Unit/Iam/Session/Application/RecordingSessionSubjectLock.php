<?php

declare(strict_types=1);

namespace Erpify\Tests\Unit\Iam\Session\Application;

use Erpify\Iam\Session\Application\SessionSubjectLock;
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
     * Per call: the user id, how many sessions had been saved, and whether a transaction was open.
     *
     * @var list<array{string, int, bool}>
     */
    public array $calls = [];

    /** What every call answers: false plays an identity erased before the lock was granted. */
    public bool $live = true;

    public function __construct(
        private readonly ?InMemorySessionRepository $sessions = null,
        private readonly ?TransactionSnapshottingManager $transactions = null,
    ) {
    }

    #[Override]
    public function holdsLive(string $userId): bool
    {
        $this->calls[] = [$userId, \count($this->sessions->saved ?? []), $this->transactions->open ?? false];

        return $this->live;
    }
}
