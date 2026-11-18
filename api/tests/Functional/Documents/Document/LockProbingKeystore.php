<?php

declare(strict_types=1);

namespace Erpify\Tests\Functional\Documents\Document;

use Closure;
use Erpify\Shared\Crypto\Application\Keystore;
use Erpify\Shared\Crypto\Application\WrappedDek;
use Erpify\Shared\Crypto\Domain\DestroyReason;
use Erpify\Shared\Crypto\Domain\EncryptionScopeId;
use Erpify\Shared\Crypto\Domain\KeyState;
use Override;

/**
 * The real keystore that tells a test, through `$probe`, when it is about to take the scope's row lock and when it has
 * it: the erasure's lock order is then read from the database (a second connection asking which rows are held) rather
 * than inferred from the order of calls.
 *
 * @internal
 */
final readonly class LockProbingKeystore implements Keystore
{
    /**
     * @param Closure(string): void $probe called with `before-lock`, `after-lock` and `before-destroy`
     */
    public function __construct(private Keystore $inner, private Closure $probe)
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
        ($this->probe)('before-destroy');

        return $this->inner->destroy($scope, $reason);
    }

    #[Override]
    public function lockState(EncryptionScopeId $scope): KeyState
    {
        ($this->probe)('before-lock');
        $state = $this->inner->lockState($scope);
        ($this->probe)('after-lock');

        return $state;
    }
}
