<?php

declare(strict_types=1);

namespace Erpify\Tests\Unit\Iam\Identity\Application;

use Erpify\Iam\Identity\Domain\Entity\PasswordResetToken;
use Erpify\Iam\Identity\Domain\Repository\PasswordResetTokenRepository;
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
    public function deleteAllForUser(string $userId): void
    {
        $this->deleteAllForUserCalls[] = $userId;

        foreach ($this->byId as $key => $token) {
            if ($token->userId() === $userId) {
                unset($this->byId[$key]);
            }
        }
    }

    private function index(PasswordResetToken $token): void
    {
        $id = $token->getId();

        if (null !== $id) {
            $this->byId[$id] = $token;
        }
    }
}
