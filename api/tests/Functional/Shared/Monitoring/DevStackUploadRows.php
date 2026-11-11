<?php

declare(strict_types=1);

namespace Erpify\Tests\Functional\Shared\Monitoring;

use CurlHandle;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use Doctrine\DBAL\Tools\DsnParser;
use Erpify\Shared\Crypto\Domain\EncryptionScopeId;
use RuntimeException;
use Symfony\Component\Process\Process;

/**
 * The rows and objects a probe through Caddy leaves in the running stack — which serves the dev database, not the
 * suite's `_test` one this process's kernel would resolve — read and then removed, and only those: the intents it
 * declared, the document a completed upload filed with its custody, its key and its sealed object, and the one
 * session it signed in with. The live-stack probes are the one place the suite touches the runtime database, and
 * they do it through the running application, on their own rows (`docs/rules/testing.md`).
 */
final class DevStackUploadRows
{
    public const string EMAIL = 'e2e@erpify.test';

    public const string PASSWORD = 'e2ePassword123';

    private const string ORGANIZATION = 'E2E-Test-Organization';

    private const string ORIGIN = 'https://localhost';

    private ?Connection $connection = null;

    /**
     * Seeds the e2e administrator the way `make pwa.test.e2e` does — idempotently: a second provision or an existing
     * administrator fails, and is fine — and deletes that identity's own declare budget from the dev application's
     * rate-limiter pool, since the budget outlives a run. No other bucket is touched.
     */
    public static function prepare(): void
    {
        foreach (
            [
                ['organization:provision', self::ORGANIZATION],
                ['organization:administrator:create', self::EMAIL, self::PASSWORD],
            ] as $command
        ) {
            self::console($command);
        }

        $actor = (new self())->connection()->fetchOne('SELECT id FROM identity_user WHERE email = :email', [
            'email' => self::EMAIL,
        ]);

        if (!\is_string($actor)) {
            throw new RuntimeException('The e2e administrator could not be seeded on the dev stack.');
        }

        $item = new Process(
            ['php', __DIR__ . '/dev-declare-budget-item.php', $actor],
            \dirname(__DIR__, 4),
            ['APP_ENV' => 'dev', 'PHPUNIT_RUNNING' => false],
        );
        $item->mustRun();
        self::console(['cache:pool:delete', 'cache.rate_limiter', \trim($item->getOutput())]);
    }

    /**
     * Signs the e2e administrator in through Caddy into the cookie jar and answers the id of the session it opened,
     * for {@see forgetSession()} to remove once the probe is done.
     */
    public static function signIn(string $cookieJar): string
    {
        $login = self::curl('/api/v1/backoffice/login', $cookieJar);
        \curl_setopt_array($login, [
            CURLOPT_CUSTOMREQUEST => 'POST',
            CURLOPT_POSTFIELDS => (string) \json_encode(['email' => self::EMAIL, 'password' => self::PASSWORD]),
            CURLOPT_HTTPHEADER => ['Content-Type: application/json', 'Origin: ' . self::ORIGIN],
        ]);
        \curl_exec($login);
        $status = \curl_getinfo($login, CURLINFO_RESPONSE_CODE);
        // curl writes the cookie jar when the handle is released, and the next request reads it.
        unset($login);

        if (204 !== $status) {
            throw new RuntimeException('The e2e administrator could not sign in through Caddy.');
        }

        $sessions = \json_decode((string) \curl_exec(self::curl('/api/v1/sessions', $cookieJar)), true);
        $current = \is_array($sessions) && \is_array($sessions['data'] ?? null) ? $sessions['data'] : [];

        foreach ($current as $session) {
            if (\is_array($session) && true === ($session['current'] ?? null) && \is_string($session['id'] ?? null)) {
                return $session['id'];
            }
        }

        throw new RuntimeException("The probe's own session is not listed.");
    }

    /**
     * Removes the one session row the probe opened. Its `SessionStarted` event stays: `event_store` is append-only.
     */
    public static function forgetSession(string $sessionId): void
    {
        $rows = new self();
        $rows->connection()->executeStatement('DELETE FROM iam_session WHERE id = :id', ['id' => $sessionId]);
        $rows->connection()->close();
    }

    /**
     * @param list<string> $command
     */
    private static function console(array $command): void
    {
        (new Process(['php', 'bin/console', ...$command, '--no-interaction'], \dirname(__DIR__, 4), [
            'APP_ENV' => 'dev',
            'PHPUNIT_RUNNING' => false,
        ]))->run();
    }

    private static function curl(string $path, string $cookieJar): CurlHandle
    {
        $handle = \curl_init(self::ORIGIN . $path);

        if (!$handle instanceof CurlHandle || '' === $cookieJar) {
            throw new RuntimeException('Could not initialise the probe.');
        }

        \curl_setopt_array($handle, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_SSL_VERIFYHOST => 0,
            CURLOPT_COOKIEFILE => $cookieJar,
            CURLOPT_COOKIEJAR => $cookieJar,
        ]);

        return $handle;
    }

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
