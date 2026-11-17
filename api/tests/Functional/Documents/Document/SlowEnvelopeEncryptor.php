<?php

declare(strict_types=1);

namespace Erpify\Tests\Functional\Documents\Document;

use Erpify\Shared\Crypto\Application\EnvelopeEncryptor;
use Erpify\Shared\Crypto\Domain\DestroyReason;
use Erpify\Shared\Crypto\Domain\EncryptionScopeId;
use Override;

/**
 * Delegates to a real encryptor and makes every batch open take `$seconds`, so a test can spend a wall-clock budget in
 * the middle of a run instead of before it.
 *
 * @internal
 */
final readonly class SlowEnvelopeEncryptor implements EnvelopeEncryptor
{
    public function __construct(
        private EnvelopeEncryptor $inner,
        private float $seconds,
    ) {
    }

    #[Override]
    public function encrypt(EncryptionScopeId $scope, string $plaintext, string $aad): string
    {
        return $this->inner->encrypt($scope, $plaintext, $aad);
    }

    #[Override]
    public function decrypt(EncryptionScopeId $scope, string $ciphertext, string $aad): string
    {
        return $this->inner->decrypt($scope, $ciphertext, $aad);
    }

    #[Override]
    public function decryptMany(array $values): array
    {
        \usleep((int) ($this->seconds * 1_000_000));

        return $this->inner->decryptMany($values);
    }

    #[Override]
    public function destroyScope(EncryptionScopeId $scope, DestroyReason $reason): bool
    {
        return $this->inner->destroyScope($scope, $reason);
    }
}
