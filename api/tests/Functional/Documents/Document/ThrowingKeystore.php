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
 * A keystore whose store is down: every read throws, as a lost database connection does, so a test can show that an
 * outage fails the page instead of degrading into a page of `null` names.
 *
 * @internal
 */
final class ThrowingKeystore implements Keystore
{
    #[Override]
    public function wrappedDekFor(EncryptionScopeId $scope): ?WrappedDek
    {
        throw new RuntimeException('The keystore is unavailable.');
    }

    #[Override]
    public function wrappedDeksFor(array $scopes): array
    {
        throw new RuntimeException('The keystore is unavailable.');
    }

    #[Override]
    public function store(EncryptionScopeId $scope, WrappedDek $dek): void
    {
        throw new RuntimeException('The keystore is unavailable.');
    }

    #[Override]
    public function destroy(EncryptionScopeId $scope, DestroyReason $reason): bool
    {
        throw new RuntimeException('The keystore is unavailable.');
    }

    #[Override]
    public function lockState(EncryptionScopeId $scope): KeyState
    {
        throw new RuntimeException('The keystore is unavailable.');
    }
}
