<?php

declare(strict_types=1);

namespace Erpify\Tests\Functional\Documents\Document;

use Erpify\Shared\Crypto\Application\Keystore;
use Erpify\Shared\Crypto\Application\WrappedDek;
use Erpify\Shared\Crypto\Domain\DestroyReason;
use Erpify\Shared\Crypto\Domain\EncryptionScopeId;
use Erpify\Shared\Crypto\Domain\KeyState;
use Override;
use RuntimeException;

/**
 * The real keystore with a fault injected at the destruction: the erasure transaction has already moved the row and
 * appended its custody row when this throws, which is the "after the compare-and-set, before the destroy" crash point.
 *
 * @internal
 */
final readonly class DestroyFaultingKeystore implements Keystore
{
    public function __construct(private Keystore $inner)
    {
    }

    #[Override]
    public function wrappedDekFor(EncryptionScopeId $scope): ?WrappedDek
    {
        return $this->inner->wrappedDekFor($scope);
    }

    #[Override]
    public function wrappedDeksFor(array $scopes): array
    {
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
        throw new RuntimeException('An injected fault between the compare-and-set and the destruction.');
    }

    #[Override]
    public function lockState(EncryptionScopeId $scope): KeyState
    {
        return $this->inner->lockState($scope);
    }
}
