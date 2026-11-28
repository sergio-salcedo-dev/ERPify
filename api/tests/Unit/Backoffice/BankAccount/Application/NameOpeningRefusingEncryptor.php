<?php

declare(strict_types=1);

namespace Erpify\Tests\Unit\Backoffice\BankAccount\Application;

use Erpify\Shared\Crypto\Application\EnvelopeEncryptor;
use Erpify\Shared\Crypto\Domain\DestroyReason;
use Erpify\Shared\Crypto\Domain\EncryptionScopeId;
use LogicException;
use Override;

/**
 * An encryptor no call may reach: the planted rows carry no sealed name, so opening names never asks the keystore.
 *
 * @internal
 */
final readonly class NameOpeningRefusingEncryptor implements EnvelopeEncryptor
{
    #[Override]
    public function encrypt(EncryptionScopeId $scope, string $plaintext, string $aad): string
    {
        throw new LogicException('Not used.');
    }

    #[Override]
    public function decrypt(EncryptionScopeId $scope, string $ciphertext, string $aad): string
    {
        throw new LogicException('Not used.');
    }

    #[Override]
    public function decryptMany(array $values): array
    {
        throw new LogicException('No row carries a sealed name, so no name is opened.');
    }

    #[Override]
    public function destroyScope(EncryptionScopeId $scope, DestroyReason $reason): bool
    {
        throw new LogicException('Not used.');
    }
}
