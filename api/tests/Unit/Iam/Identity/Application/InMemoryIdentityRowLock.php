<?php

declare(strict_types=1);

namespace Erpify\Tests\Unit\Iam\Identity\Application;

use Closure;
use Erpify\Iam\Identity\Application\IdentityRowSerialiser;
use Erpify\Iam\Identity\Domain\Repository\IdentityRowLock;
use Override;

/**
 * {@see IdentityRowLock} over a set of erased ids: every other id answers live, so a test that is not about
 * the erasure window gets the write it always got, and one that is names the subject it erased.
 *
 * @internal
 */
final class InMemoryIdentityRowLock implements IdentityRowLock
{
    /** @var list<string> every id a lock was asked for, in order */
    public array $locked = [];

    /** @var list<string> */
    public array $gone = [];

    /** Runs when the lock is asked for, before it answers — the seam an ordering test journals through. */
    public ?Closure $onLock = null;

    /**
     * A serialiser over `$rows`, or over a fresh double treating every subject as live — the latter for the
     * many tests that build a recorder only because the use case under test needs one.
     */
    public static function serialiser(?self $rows = null): IdentityRowSerialiser
    {
        return new IdentityRowSerialiser($rows ?? new self(), new InlineTransactionManager());
    }

    #[Override]
    public function lockIfLive(string $userId): bool
    {
        $this->locked[] = $userId;

        if ($this->onLock instanceof Closure) {
            ($this->onLock)($userId);
        }

        return !\in_array($userId, $this->gone, true);
    }
}
