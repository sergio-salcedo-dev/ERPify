<?php

declare(strict_types=1);

namespace Erpify\Tests\Functional\Iam\Identity;

use Erpify\Iam\Identity\Infrastructure\Security\Rfc6238TotpAlgorithm;
use Erpify\Shared\Clock\Domain\Clock;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * The requests, the code computation and the row reads the second-factor enrolment tests share. The using class
 * declares `EMAIL` (the dedicated identity it drives, so two classes never share a factor row) and
 * `CURRENT_PASSWORD`, and uses {@see \Erpify\Tests\Functional\AuthenticatesFunctionalRequests} for the connection.
 */
trait DrivesTotpEnrolment
{
    private const string ENROL_ENDPOINT = '/api/v1/me/second-factor/totp';

    private const string CONFIRM_ENDPOINT = '/api/v1/me/second-factor/totp/confirm';

    private const string BASE32_ALPHABET = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';

    /**
     * @param array<string, string> $body
     */
    private function post(KernelBrowser $client, string $endpoint, array $body): void
    {
        $client->request(
            Request::METHOD_POST,
            $endpoint,
            server: ['CONTENT_TYPE' => 'application/json'],
            content: (string) \json_encode($body),
        );
    }

    /**
     * Starts an enrolment with the current password and answers the secret it showed, after asserting the 201 and
     * that the one response carrying the secret is kept by no cache.
     */
    private function enrol(KernelBrowser $client): string
    {
        $this->post($client, self::ENROL_ENDPOINT, ['currentPassword' => self::CURRENT_PASSWORD]);

        $this->assertStatus($client, Response::HTTP_CREATED);
        $this->assertStringContainsString(
            'no-store',
            (string) $client->getResponse()->headers->get('Cache-Control'),
            'the one response carrying the secret must not be kept by any cache',
        );
        $secret = $this->jsonBody($client)['secret'] ?? null;
        $this->assertIsString($secret);
        $this->assertMatchesRegularExpression('/^[A-Z2-7]{32}$/D', $secret, '160 bits in unpadded base32');

        return $secret;
    }

    private function assertStatus(KernelBrowser $client, int $expected): void
    {
        $this->assertSame(
            $expected,
            $client->getResponse()->getStatusCode(),
            (string) $client->getResponse()->getContent(),
        );
    }

    /**
     * The resource under the response's `data` envelope, the shape every single-resource response takes.
     *
     * @return array<array-key, mixed>
     */
    private function jsonBody(KernelBrowser $client): array
    {
        $decoded = \json_decode((string) $client->getResponse()->getContent(), true, 512, JSON_THROW_ON_ERROR);
        $this->assertIsArray($decoded);
        $resource = $decoded['data'] ?? null;
        $this->assertIsArray($resource, 'a single-resource response is enveloped under `data`');

        return $resource;
    }

    /**
     * The code an authenticator holding `$encodedSecret` shows now, at the application's own clock.
     */
    private function currentCode(string $encodedSecret): string
    {
        return $this->currentCodeOf($this->decodedSecret($encodedSecret));
    }

    /**
     * The code an authenticator holding the raw `$secret` bytes shows now, at the application's own clock.
     */
    private function currentCodeOf(string $secret): string
    {
        return (new Rfc6238TotpAlgorithm())->codeAt(
            $secret,
            \intdiv($this->applicationClock()->now()->getTimestamp(), Rfc6238TotpAlgorithm::PERIOD_SECONDS),
        );
    }

    /**
     * A code that is not the current one, so a confirmation carrying it is a wrong code rather than a lucky one.
     */
    private function wrongCode(string $encodedSecret): string
    {
        return '000000' === $this->currentCode($encodedSecret) ? '111111' : '000000';
    }

    private function applicationClock(): Clock
    {
        $clock = self::getContainer()->get(Clock::class);
        $this->assertInstanceOf(Clock::class, $clock);

        return $clock;
    }

    private function decodedSecret(string $encoded): string
    {
        $bits = '';

        foreach (\str_split($encoded) as $character) {
            $value = \strpos(self::BASE32_ALPHABET, $character);
            $this->assertIsInt($value);
            $bits .= \str_pad(\decbin($value), 5, '0', STR_PAD_LEFT);
        }

        $bytes = '';

        foreach (\str_split(\substr($bits, 0, \intdiv(\strlen($bits), 8) * 8), 8) as $octet) {
            $bytes .= \chr((int) \bindec($octet) & 0xFF);
        }

        return $bytes;
    }

    /**
     * @return list<string> `pending` or `enabled` for each factor row the identity holds
     */
    private function storedFactorStates(): array
    {
        $rows = $this->functionalConnection()->fetchFirstColumn(
            "SELECT CASE WHEN enabled_at IS NULL THEN 'pending' ELSE 'enabled' END FROM identity_totp_factor "
            . 'WHERE user_id = CAST(:id AS uuid)',
            ['id' => $this->identityId()],
        );

        return \array_values(\array_filter($rows, \is_string(...)));
    }

    private function storedSealedSecret(): string
    {
        $sealed = $this->functionalConnection()->fetchOne(
            'SELECT sealed_secret FROM identity_totp_factor WHERE user_id = CAST(:id AS uuid)',
            ['id' => $this->identityId()],
        );
        $this->assertIsString($sealed);

        return $sealed;
    }

    private function storedFailedConfirmAttempts(): int
    {
        $count = $this->functionalConnection()->fetchOne(
            'SELECT failed_confirm_attempts FROM identity_totp_factor WHERE user_id = CAST(:id AS uuid)',
            ['id' => $this->identityId()],
        );
        $this->assertIsNumeric($count);

        return (int) $count;
    }

    private function activeSessionCount(): int
    {
        $count = $this->functionalConnection()->fetchOne(
            "SELECT count(*) FROM iam_session WHERE user_id = CAST(:id AS uuid) AND status = 'ACTIVE'",
            ['id' => $this->identityId()],
        );
        $this->assertIsNumeric($count);

        return (int) $count;
    }

    private function auditRowCount(string $action): int
    {
        $count = $this->functionalConnection()->fetchOne(
            'SELECT count(*) FROM audit_log WHERE action = :action AND resource_id = CAST(:id AS uuid)',
            ['action' => $action, 'id' => $this->identityId()],
        );
        $this->assertIsNumeric($count);

        return (int) $count;
    }

    private function identityId(): string
    {
        $id = $this->functionalConnection()->fetchOne(
            'SELECT id FROM identity_user WHERE email = :email',
            ['email' => self::EMAIL],
        );
        $this->assertIsString($id);

        return $id;
    }

    /**
     * Deletes the identity and every factor it held, so `authenticateAs()` recreates it with a known credential
     * and no factor. The factor's key is left live in `dek_keystore`: it seals nothing once the row is gone, and
     * a test-only tombstone would read as an unanswered erasure to the subject-erasure reconciler.
     */
    private function purgeIdentity(): void
    {
        $connection = $this->functionalConnection();
        $id = $connection->fetchOne('SELECT id FROM identity_user WHERE email = :email', ['email' => self::EMAIL]);

        if (!\is_string($id)) {
            return;
        }

        $connection->executeStatement(
            'DELETE FROM identity_totp_factor WHERE user_id = CAST(:id AS uuid)',
            ['id' => $id],
        );
        $connection->executeStatement('DELETE FROM iam_session WHERE user_id = CAST(:id AS uuid)', ['id' => $id]);
        $connection->executeStatement('DELETE FROM identity_user WHERE id = CAST(:id AS uuid)', ['id' => $id]);
    }
}
