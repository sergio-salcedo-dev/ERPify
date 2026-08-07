<?php

declare(strict_types=1);

namespace Erpify\Tests\Unit\Iam\Identity\Application;

use DateTimeImmutable;
use Erpify\Iam\Identity\Domain\Entity\PasswordResetToken;
use Erpify\Iam\Identity\Domain\Repository\PasswordResetTokenRepository;
use Erpify\Tests\Unit\Shared\Persistence\Double\LockOrderJournal;
use Override;

/**
 * In-memory {@see PasswordResetTokenRepository} that records every mutation, so a use-case test can assert what
 * a case persists, consumes (the single-use retire) and supersedes.
 *
 * @internal
 */
final class InMemoryPasswordResetTokenRepository implements PasswordResetTokenRepository
{
    /** @var list<PasswordResetToken> */
    public array $saved = [];

    /** @var list<PasswordResetToken> */
    public array $consumed = [];

    /** @var list<string> userIds passed to deleteAllForUser */
    public array $deleteAllForUserCalls = [];

    /** Set when a test is asserting WHERE this table's lock falls among the others. */
    public ?LockOrderJournal $lockOrderJournal = null;

    /** @var array<string, PasswordResetToken> */
    private array $byId = [];

    public function __construct(PasswordResetToken ...$preset)
    {
        foreach ($preset as $token) {
            $this->index($token);
        }
    }

    #[Override]
    public function save(PasswordResetToken $token): void
    {
        $this->saved[] = $token;
        $this->index($token);
    }

    #[Override]
    public function consume(PasswordResetToken $token): bool
    {
        $this->lockOrderJournal?->locked(LockOrderJournal::PASSWORD_RESET_TOKEN);

        $id = $token->getId();

        if (null === $id || !isset($this->byId[$id])) {
            return false;
        }

        $this->consumed[] = $token;
        unset($this->byId[$id]);

        return true;
    }

    #[Override]
    public function findById(string $id): ?PasswordResetToken
    {
        return $this->byId[$id] ?? null;
    }

    #[Override]
    public function deleteAllForUser(string $userId): int
    {
        $this->lockOrderJournal?->locked(LockOrderJournal::PASSWORD_RESET_TOKEN);

        $this->deleteAllForUserCalls[] = $userId;
        $deleted = 0;

        foreach ($this->byId as $key => $token) {
            // Case-insensitive like the Postgres `uuid` column the real adapter matches on; a `!==` here would
            // make the double STRICTER than production, and no test could fail on the difference.
            if (0 === \strcasecmp($token->userId(), $userId)) {
                unset($this->byId[$key]);
                ++$deleted;
            }
        }

        return $deleted;
    }

    #[Override]
    public function deleteExpired(DateTimeImmutable $now): int
    {
        $deleted = 0;

        foreach ($this->byId as $key => $token) {
            if ($token->isExpiredAt($now)) {
                unset($this->byId[$key]);
                ++$deleted;
            }
        }

        return $deleted;
    }

    private function index(PasswordResetToken $token): void
    {
        $id = $token->getId();

        if (null !== $id) {
            $this->byId[$id] = $token;
        }
    }
}
