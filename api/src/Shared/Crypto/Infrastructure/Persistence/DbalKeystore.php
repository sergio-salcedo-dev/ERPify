<?php

declare(strict_types=1);

namespace Erpify\Shared\Crypto\Infrastructure\Persistence;

use Doctrine\DBAL\Connection;
use Erpify\Shared\Crypto\Application\Keystore;
use Erpify\Shared\Crypto\Application\WrappedDek;
use Erpify\Shared\Crypto\Domain\EncryptionScopeId;
use Erpify\Shared\Crypto\Domain\Exception\DecryptionFailed;
use Override;
use SodiumException;
use Symfony\Component\DependencyInjection\Attribute\AsAlias;

/**
 * {@see Keystore} over the `dek_keystore` table via plain DBAL. Owns no transaction and uses the default
 * connection, so a `store` issued inside a business flush enrols in that transaction (the wrapped key and
 * the change it protects commit together). `store` is idempotent (`ON CONFLICT DO NOTHING`); `destroy` is
 * idempotent too (it only touches a still-live key) and preserves the row so a shredding stays reconcilable.
 */
#[AsAlias(Keystore::class)]
final readonly class DbalKeystore implements Keystore
{
    public function __construct(
        private Connection $connection,
    ) {
    }

    #[Override]
    public function wrappedDekFor(EncryptionScopeId $scope): ?WrappedDek
    {
        $row = $this->connection->fetchAssociative(
            'SELECT wrapped_dek, kek_version FROM dek_keystore '
            . 'WHERE encryption_scope_id = :scope AND destroyed_at IS NULL',
            ['scope' => $scope->toString()],
        );

        if (false === $row) {
            return null;
        }

        $wrappedDek = $row['wrapped_dek'] ?? null;
        $kekVersion = $row['kek_version'] ?? null;

        // A live row (destroyed_at IS NULL) with no usable key material is corruption, not a shredded
        // scope: fail loudly instead of masquerading as an absent key, which a caller would read as
        // "destroyed" (DekDestroyed) and mistake for a legitimate crypto-shred.
        if (!\is_string($wrappedDek) || !\is_numeric($kekVersion)) {
            throw DecryptionFailed::forScope($scope);
        }

        try {
            $bytes = \sodium_base642bin($wrappedDek, SODIUM_BASE64_VARIANT_ORIGINAL);
        } catch (SodiumException) {
            throw DecryptionFailed::forScope($scope);
        }

        return new WrappedDek($bytes, (int) $kekVersion);
    }

    #[Override]
    public function store(EncryptionScopeId $scope, WrappedDek $dek): void
    {
        $this->connection->executeStatement(
            'INSERT INTO dek_keystore (encryption_scope_id, wrapped_dek, kek_version, created_at) '
            . 'VALUES (:scope, :wrappedDek, :kekVersion, NOW()) '
            . 'ON CONFLICT (encryption_scope_id) DO NOTHING',
            [
                'scope' => $scope->toString(),
                'wrappedDek' => \sodium_bin2base64($dek->bytes, SODIUM_BASE64_VARIANT_ORIGINAL),
                'kekVersion' => $dek->kekVersion,
            ],
        );
    }

    #[Override]
    public function destroy(EncryptionScopeId $scope): bool
    {
        $affected = $this->connection->executeStatement(
            'UPDATE dek_keystore SET destroyed_at = NOW(), wrapped_dek = NULL '
            . 'WHERE encryption_scope_id = :scope AND destroyed_at IS NULL',
            ['scope' => $scope->toString()],
        );

        return $affected > 0;
    }
}
