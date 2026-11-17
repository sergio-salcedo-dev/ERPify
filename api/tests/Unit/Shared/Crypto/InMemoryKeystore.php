<?php

declare(strict_types=1);

namespace Erpify\Tests\Unit\Shared\Crypto;

use Erpify\Shared\Crypto\Application\Keystore;
use Erpify\Shared\Crypto\Application\WrappedDek;
use Erpify\Shared\Crypto\Domain\DestroyReason;
use Erpify\Shared\Crypto\Domain\EncryptionScopeId;
use Erpify\Shared\Crypto\Domain\Exception\DecryptionFailed;
use Erpify\Shared\Crypto\Domain\Exception\KeystoreLockRequiresTransaction;
use Erpify\Shared\Crypto\Domain\KeyState;
use Erpify\Shared\Crypto\Infrastructure\Persistence\DbalKeystore;
use Override;

/**
 * In-memory {@see Keystore} for unit tests. Mirrors the production contract faithfully, tombstone included:
 * first write wins (`ON CONFLICT DO NOTHING`); destroying a scope keeps a surviving tombstone so its key
 * reads as unavailable, a scope never minted is tombstoned too, the first tombstone keeps its reason, the
 * destroy is idempotent, and a later `store` for that scope is a no-op — the scope can never be re-minted,
 * exactly as the surviving `destroyed_at` row enforces in Postgres.
 *
 * It does not model the transaction `lockState()` requires of {@see DbalKeystore}, nor any lock: it never
 * throws {@see KeystoreLockRequiresTransaction} and nothing blocks. A test of that requirement or of
 * serialisation between writers belongs against the real keystore.
 */
final class InMemoryKeystore implements Keystore
{
    /** @var array<string, WrappedDek> */
    private array $live = [];

    /** @var array<string, DestroyReason> */
    private array $tombstoned = [];

    /** @var array<string, WrappedDek|DecryptionFailed> what the batch read answers instead, for a scope a test corrupted */
    private array $batchOverrides = [];

    #[Override]
    public function wrappedDekFor(EncryptionScopeId $scope): ?WrappedDek
    {
        return $this->live[$scope->toString()] ?? null;
    }

    #[Override]
    public function wrappedDeksFor(array $scopes): array
    {
        $found = [];

        foreach ($scopes as $scope) {
            $key = $scope->toString();

            if (isset($this->batchOverrides[$key])) {
                $found[$key] = $this->batchOverrides[$key];
            } elseif (isset($this->live[$key])) {
                $found[$key] = $this->live[$key];
            }
        }

        return $found;
    }

    #[Override]
    public function store(EncryptionScopeId $scope, WrappedDek $dek): void
    {
        $key = $scope->toString();

        if (isset($this->tombstoned[$key])) {
            return;
        }

        $this->live[$key] ??= $dek;
    }

    #[Override]
    public function destroy(EncryptionScopeId $scope, DestroyReason $reason): bool
    {
        $key = $scope->toString();

        if (isset($this->tombstoned[$key])) {
            return false;
        }

        unset($this->live[$key]);
        $this->tombstoned[$key] = $reason;

        return true;
    }

    #[Override]
    public function lockState(EncryptionScopeId $scope): KeyState
    {
        $key = $scope->toString();

        return match (true) {
            isset($this->tombstoned[$key]) => KeyState::Tombstoned,
            isset($this->live[$key]) => KeyState::Live,
            default => KeyState::Absent,
        };
    }

    /**
     * Makes the batch read answer `$entry` for the scope, as the real keystore does for a corrupt live row
     * ({@see DecryptionFailed}) or for a row holding bytes of its own choosing.
     */
    public function corruptInBatchReads(EncryptionScopeId $scope, DecryptionFailed|WrappedDek $entry): void
    {
        $this->batchOverrides[$scope->toString()] = $entry;
    }

    public function reasonFor(EncryptionScopeId $scope): ?DestroyReason
    {
        return $this->tombstoned[$scope->toString()] ?? null;
    }
}
