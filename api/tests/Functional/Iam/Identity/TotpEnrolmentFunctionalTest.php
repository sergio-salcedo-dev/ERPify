<?php

declare(strict_types=1);

namespace Erpify\Tests\Functional\Iam\Identity;

use Erpify\Iam\Identity\Infrastructure\Controller\ConfirmTotpFactorController;
use Erpify\Iam\Identity\Infrastructure\Controller\EnrolTotpFactorController;
use Erpify\Iam\Identity\Infrastructure\Http\ConfirmTotpFactorRequest;
use Erpify\Iam\Identity\Infrastructure\Http\EnrolTotpFactorRequest;
use Erpify\Iam\Identity\Infrastructure\Security\Rfc6238TotpAlgorithm;
use Erpify\Shared\Access\Domain\Role;
use Erpify\Shared\Clock\Domain\Clock;
use Erpify\Tests\Functional\AuthenticatesFunctionalRequests;
use PHPUnit\Framework\Attributes\CoversClass;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * The WIRING of the two enrolment routes, through the real router, firewall, argument resolvers and database:
 * that a password re-proof starts a pending enrolment and shows its secret once, that a code computed from that
 * secret enables the factor, and that the coarse refusals arrive on these URLs. The order of the proof against
 * the factor lookup, the lock order and the window rules are pinned by the use-case and algorithm unit tests.
 *
 * `WebTestCase` rather than Behat for the reason the sibling recovery-secret test gives: the classes the request
 * passes through are credited to the coverage report.
 *
 * Every test starts from a purged identity, because the suite shares a long-lived database that is never rolled
 * back, and a factor left by an earlier run would decide the 201/409 answers on its own.
 *
 * @internal
 */
#[CoversClass(EnrolTotpFactorController::class)]
#[CoversClass(ConfirmTotpFactorController::class)]
#[CoversClass(EnrolTotpFactorRequest::class)]
#[CoversClass(ConfirmTotpFactorRequest::class)]
final class TotpEnrolmentFunctionalTest extends WebTestCase
{
    use AuthenticatesFunctionalRequests;

    /** A dedicated identity, so enabling its factor cannot disturb the shared functional users. */
    private const string EMAIL = 'totp-enrolment@erpify.test';

    private const string CURRENT_PASSWORD = 'functional-password';

    private const string ENROL_ENDPOINT = '/api/v1/me/second-factor/totp';

    private const string CONFIRM_ENDPOINT = '/api/v1/me/second-factor/totp/confirm';

    private const string BASE32_ALPHABET = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';

    public function testAProvenEnrolmentShowsTheSecretOnceAndAValidCodeEnablesTheFactor(): void
    {
        $client = self::createClient();
        $this->purgeIdentity();
        $this->authenticateAs($client, self::EMAIL, [Role::VIEWER->value]);

        $this->post($client, self::ENROL_ENDPOINT, ['currentPassword' => self::CURRENT_PASSWORD]);

        $this->assertSame(
            Response::HTTP_CREATED,
            $client->getResponse()->getStatusCode(),
            (string) $client->getResponse()->getContent(),
        );
        $body = $this->jsonBody($client);
        $secret = $body['secret'] ?? null;
        $this->assertIsString($secret);
        $this->assertMatchesRegularExpression('/^[A-Z2-7]{32}$/D', $secret, '160 bits in unpadded base32');
        $provisioningUri = $body['provisioningUri'] ?? null;
        $this->assertIsString($provisioningUri);
        $this->assertStringContainsString('secret=' . $secret, $provisioningUri);
        $this->assertSame(['pending'], $this->storedFactorStates(), 'an enrolment gates nothing until confirmed');
        $this->assertStringNotContainsString(
            $secret,
            $this->storedSealedSecret(),
            'the row must hold ciphertext, never the secret it was shown with',
        );
        $this->assertSame(1, $this->auditRowCount('SECOND_FACTOR_ENROLMENT_STARTED'));

        $this->post($client, self::CONFIRM_ENDPOINT, ['code' => $this->currentCode($secret)]);

        $this->assertSame(
            Response::HTTP_OK,
            $client->getResponse()->getStatusCode(),
            (string) $client->getResponse()->getContent(),
        );
        $this->assertIsString($this->jsonBody($client)['enabledAt'] ?? null);
        $this->assertStringNotContainsString($secret, (string) $client->getResponse()->getContent());
        $this->assertSame(['enabled'], $this->storedFactorStates());
        $this->assertSame(1, $this->auditRowCount('SECOND_FACTOR_ENABLED'));

        // Once enabled, no route shows the secret again: a second enrolment is refused rather than re-issued.
        $this->post($client, self::ENROL_ENDPOINT, ['currentPassword' => self::CURRENT_PASSWORD]);

        $this->assertSame(Response::HTTP_CONFLICT, $client->getResponse()->getStatusCode());
        $this->assertStringContainsString(
            'second-factor-already-enabled',
            (string) $client->getResponse()->getContent(),
        );
        $this->assertStringNotContainsString($secret, (string) $client->getResponse()->getContent());

        $client->request(Request::METHOD_GET, '/api/v1/me');
        $this->assertStringNotContainsString($secret, (string) $client->getResponse()->getContent());
    }

    public function testAWrongCurrentPasswordIsRefusedAndNothingIsEnrolled(): void
    {
        $client = self::createClient();
        $this->purgeIdentity();
        $this->authenticateAs($client, self::EMAIL, [Role::VIEWER->value]);

        $this->post($client, self::ENROL_ENDPOINT, ['currentPassword' => 'not-the-current-password']);

        $this->assertSame(
            Response::HTTP_FORBIDDEN,
            $client->getResponse()->getStatusCode(),
            (string) $client->getResponse()->getContent(),
        );
        $this->assertStringContainsString('invalid-current-password', (string) $client->getResponse()->getContent());
        $this->assertSame([], $this->storedFactorStates());
    }

    public function testAWrongCodeIsRefusedAndTheFactorStaysPending(): void
    {
        $client = self::createClient();
        $this->purgeIdentity();
        $this->authenticateAs($client, self::EMAIL, [Role::VIEWER->value]);
        $this->post($client, self::ENROL_ENDPOINT, ['currentPassword' => self::CURRENT_PASSWORD]);
        $secret = $this->jsonBody($client)['secret'] ?? null;
        $this->assertIsString($secret);
        $wrong = '000000' === $this->currentCode($secret) ? '111111' : '000000';

        $this->post($client, self::CONFIRM_ENDPOINT, ['code' => $wrong]);

        $this->assertSame(
            Response::HTTP_FORBIDDEN,
            $client->getResponse()->getStatusCode(),
            (string) $client->getResponse()->getContent(),
        );
        $this->assertStringContainsString('invalid-second-factor-code', (string) $client->getResponse()->getContent());
        $this->assertSame(['pending'], $this->storedFactorStates());
    }

    public function testConfirmingWithNoEnrolmentIsAConflict(): void
    {
        $client = self::createClient();
        $this->purgeIdentity();
        $this->authenticateAs($client, self::EMAIL, [Role::VIEWER->value]);

        $this->post($client, self::CONFIRM_ENDPOINT, ['code' => '123456']);

        $this->assertSame(Response::HTTP_CONFLICT, $client->getResponse()->getStatusCode());
        $this->assertStringContainsString('second-factor-not-enrolled', (string) $client->getResponse()->getContent());
    }

    /**
     * The strict resolver really runs on both routes: a member neither payload declares is refused before the use
     * case, so nothing is enrolled.
     */
    public function testABodyMemberTheEndpointsDoNotDeclareIsRefused(): void
    {
        $client = self::createClient();
        $this->purgeIdentity();
        $this->authenticateAs($client, self::EMAIL, [Role::VIEWER->value]);

        $this->post($client, self::ENROL_ENDPOINT, [
            'currentPassword' => self::CURRENT_PASSWORD,
            'secret' => 'MY-OWN-SECRET',
        ]);
        $this->assertSame(Response::HTTP_UNPROCESSABLE_ENTITY, $client->getResponse()->getStatusCode());
        $this->assertSame([], $this->storedFactorStates());

        $this->post($client, self::CONFIRM_ENDPOINT, ['code' => '123456', 'force' => 'yes']);
        $this->assertSame(Response::HTTP_UNPROCESSABLE_ENTITY, $client->getResponse()->getStatusCode());
    }

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
     * @return array<array-key, mixed>
     */
    private function jsonBody(KernelBrowser $client): array
    {
        $decoded = \json_decode((string) $client->getResponse()->getContent(), true, 512, JSON_THROW_ON_ERROR);
        $this->assertIsArray($decoded);

        return $decoded;
    }

    /**
     * The code an authenticator holding `$encodedSecret` shows now, at the application's own clock.
     */
    private function currentCode(string $encodedSecret): string
    {
        $clock = static::getContainer()->get(Clock::class);
        $this->assertInstanceOf(Clock::class, $clock);
        $now = $clock->now();

        return (new Rfc6238TotpAlgorithm())->codeAt(
            $this->decodedSecret($encodedSecret),
            \intdiv($now->getTimestamp(), Rfc6238TotpAlgorithm::PERIOD_SECONDS),
        );
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
            $bytes .= \chr((int) \bindec($octet));
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
     * Deletes the identity and every factor it held, so {@see authenticateAs} recreates it with a known credential
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
