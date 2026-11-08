<?php

declare(strict_types=1);

namespace Erpify\Tests\Functional\Shared\Monitoring;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use Doctrine\DBAL\Tools\DsnParser;
use Erpify\Shared\Crypto\Domain\EncryptionScopeId;
use RuntimeException;

/**
 * The rows and objects a probe through Caddy leaves in the running stack — which serves the dev database, not the
 * suite's `_test` one this process's kernel would resolve — read and then removed: the intents it declared, the
 * document a completed upload filed with its custody, its key and its sealed object.
 */
final class DevStackUploadRows
{
    private ?Connection $connection = null;

    public function stateOf(string $intentId): mixed
    {
        return $this->connection()->fetchOne('SELECT state FROM upload_intent WHERE id = :id', ['id' => $intentId]);
    }

    /**
     * @param list<string> $intents
     * @param list<string> $documents
     */
    public function remove(array $intents, array $documents): void
    {
        $connection = $this->connection();

        foreach ($documents as $document) {
            $scope = $connection->fetchOne('SELECT scope_id FROM document WHERE id = :id', ['id' => $document]);
            $connection->executeStatement('DELETE FROM document_custody WHERE document_id = :id', ['id' => $document]);
            $connection->executeStatement('DELETE FROM document WHERE id = :id', ['id' => $document]);
            $this->removeScope($scope);
        }

        foreach ($intents as $intent) {
            $scope = $connection->fetchOne('SELECT scope_id FROM upload_intent WHERE id = :id', ['id' => $intent]);
            $connection->executeStatement('DELETE FROM upload_intent WHERE id = :id', ['id' => $intent]);
            $this->removeScope($scope);
        }

        $connection->close();
    }

    private function removeScope(mixed $scope): void
    {
        if (!\is_string($scope)) {
            return;
        }

        $this->connection()->executeStatement('DELETE FROM dek_keystore WHERE encryption_scope_id = :scope', [
            'scope' => EncryptionScopeId::forDocument($scope)->toString(),
        ]);

        $root = \getenv('DOCUMENTS_STORAGE_PATH');

        foreach (['staging', 'final'] as $area) {
            $path = \sprintf('%s/%s/%s/%s/%s', $root, $area, \substr($scope, -4, 2), \substr($scope, -2), $scope);

            if (\is_string($root) && '' !== $root && \is_file($path)) {
                \unlink($path);
            }
        }
    }

    private function connection(): Connection
    {
        if ($this->connection instanceof Connection) {
            return $this->connection;
        }

        $url = \getenv('DATABASE_URL');

        if (!\is_string($url) || '' === $url) {
            throw new RuntimeException('DATABASE_URL is not set, so the probe rows cannot be found or removed.');
        }

        return $this->connection = DriverManager::getConnection(
            (new DsnParser(['postgresql' => 'pdo_pgsql']))->parse($url),
        );
    }
}
