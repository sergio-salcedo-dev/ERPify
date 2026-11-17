<?php

declare(strict_types=1);

namespace Erpify\Tests\Unit\Shared\Crypto;

use Erpify\Shared\Crypto\Application\Keystore;
use Erpify\Shared\Crypto\Application\WrappedDek;
use Erpify\Shared\Crypto\Domain\DestroyReason;
use Erpify\Shared\Crypto\Domain\EncryptionScopeId;
use Erpify\Shared\Crypto\Domain\KeyState;
use Override;

/**
 * Decorates any {@see Keystore} and counts the reads of live keys, split by the method that asked, so a test can say
 * "one query for the whole page" about the single-scope and the batch read separately. Every call is delegated.
 */
final class CountingKeystore implements Keystore
{
    public int $singleReads = 0;

    public int $batchReads = 0;

    public function __construct(private readonly Keystore $inner)
    {
    }

    #[Override]
    public function wrappedDekFor(EncryptionScopeId $scope): ?WrappedDek
    {
        ++$this->singleReads;

        return $this->inner->wrappedDekFor($scope);
    }

    #[Override]
    public function wrappedDeksFor(array $scopes): array
    {
        ++$this->batchReads;

        return $this->inner->wrappedDeksFor($scopes);
    }

    #[Override]
    public function store(EncryptionScopeId $scope, WrappedDek $dek): void
    {
        $this->inner->store($scope, $dek);
    }

    #[Override]
    public function destroy(EncryptionScopeId $scope, DestroyReason $reason): bool
    {
        return $this->inner->destroy($scope, $reason);
    }

    #[Override]
    public function lockState(EncryptionScopeId $scope): KeyState
    {
        return $this->inner->lockState($scope);
    }

    public function reads(): int
    {
        return $this->singleReads + $this->batchReads;
    }
}
