<?php

declare(strict_types=1);

namespace Erpify\Shared\Crypto\Application;

use Erpify\Shared\Crypto\Domain\EncryptionScopeId;

/**
 * One value waiting to be opened by {@see EnvelopeEncryptor::decryptMany()}: the scope it was sealed under, the
 * ciphertext token {@see EnvelopeEncryptor::encrypt()} returned and the additional-authenticated-data it was sealed
 * with — the three things {@see EnvelopeEncryptor::decrypt()} takes one call at a time.
 */
final readonly class SealedValue
{
    public function __construct(
        public EncryptionScopeId $scope,
        public string $ciphertext,
        public string $aad,
    ) {
    }
}
