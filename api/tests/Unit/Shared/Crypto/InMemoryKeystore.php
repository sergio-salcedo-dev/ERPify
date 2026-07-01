<?php

declare(strict_types=1);

namespace Erpify\Tests\Unit\Shared\Crypto;

use Erpify\Shared\Crypto\Application\Keystore;
use Erpify\Shared\Crypto\Application\WrappedDek;
use Erpify\Shared\Crypto\Domain\EncryptionScopeId;
use Override;

/**
 * In-memory {@see Keystore} for unit tests. Mirrors the production contract: first write wins
 * (`ON CONFLICT DO NOTHING`) and destroying a scope makes its key unreadable and is idempotent.
 */
final class InMemoryKeystore implements Keystore
{
    /** @var array<string, WrappedDek> */
    private array $live = [];

    #[Override]
    public function wrappedDekFor(EncryptionScopeId $scope): ?WrappedDek
    {
        return $this->live[$scope->toString()] ?? null;
    }

    #[Override]
    public function store(EncryptionScopeId $scope, WrappedDek $dek): void
    {
        $this->live[$scope->toString()] ??= $dek;
    }

    #[Override]
    public function destroy(EncryptionScopeId $scope): bool
    {
        $key = $scope->toString();

        if (!isset($this->live[$key])) {
            return false;
        }

        unset($this->live[$key]);

        return true;
    }
}
