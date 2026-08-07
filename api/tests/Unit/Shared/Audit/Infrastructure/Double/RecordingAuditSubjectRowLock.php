<?php

declare(strict_types=1);

namespace Erpify\Tests\Unit\Shared\Audit\Infrastructure\Double;

use Closure;
use Erpify\Shared\Audit\Application\AuditSubjectRowLock;
use Erpify\Shared\Audit\Domain\AuditResource;
use Override;

/**
 * Spy {@see AuditSubjectRowLock}: records the subject it was asked to lock and, at that instant, how much
 * anonymisation had already happened.
 *
 * The second field is the point. A lock taken after the first `UPDATE` orders nothing — the statement that
 * could deadlock has already run — so a test asserting only that `lock()` was called would pass over the one
 * arrangement the lock exists to forbid. Counting the anonymiser calls *at lock time* is what makes "before"
 * an assertion rather than an implication.
 *
 * @internal
 */
final class RecordingAuditSubjectRowLock implements AuditSubjectRowLock
{
    /** @var list<AuditResource> */
    public array $locked = [];

    /** @var list<int> */
    public array $anonymisationsAlreadyRun = [];

    /**
     * @param (Closure(): int)|null $anonymisationCounter total anonymiser calls made so far; omit when a test
     *                                                    only cares that the lock was taken at all
     */
    public function __construct(
        private readonly ?Closure $anonymisationCounter = null,
    ) {
    }

    #[Override]
    public function lock(AuditResource $subject): void
    {
        $this->locked[] = $subject;

        if ($this->anonymisationCounter instanceof Closure) {
            $this->anonymisationsAlreadyRun[] = ($this->anonymisationCounter)();
        }
    }
}
