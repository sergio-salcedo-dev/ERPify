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
use Erpify\Shared\Uuid\Domain\Uuid;
use Erpify\Tests\Functional\AssertsSessionIdRotation;
use Erpify\Tests\Functional\AuthenticatesFunctionalRequests;
use Erpify\Tests\Functional\SeedsAnOrganizationMember;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\TestDox;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * The WIRING of the two enrolment routes, through the real router, firewall, argument resolvers and database:
 * that a password re-proof starts a pending enrolment and shows its secret once, that a code computed from that
 * secret enables the factor, and that the coarse refusals arrive on these URLs. The order of the proof against
 * the factor lookup, the lock order and the window rules are pinned by the use-case and algorithm unit tests; the
 * non-`ACTIVE` walls by the use-case tests too, since the firewall drops a session whose identity changed status
 * before any route runs. A concurrent first enrolment (409 `concurrent-unique-write`) is not provoked here: both
 * requests lock the identity row first, so the second one finds the first's row and restarts it.
 *
 * `WebTestCase` rather than Behat for the reason the sibling recovery-secret test gives: the classes the request
 * passes through are credited to the coverage report.
 *
 * Every test starts from a purged identity, because the suite shares a long-lived database that is never rolled
 * back, and a factor left by an earlier run would decide the 201/409 answers on its own.
 *
 * @internal
 *
 * @SuppressWarnings("PHPMD.CouplingBetweenObjects") the two routes, their payloads, the algorithm that computes a
 *                                                   real code, the clock it reads and the session harness
 */
#[CoversClass(EnrolTotpFactorController::class)]
#[CoversClass(ConfirmTotpFactorController::class)]
#[CoversClass(EnrolTotpFactorRequest::class)]
#[CoversClass(ConfirmTotpFactorRequest::class)]
final class TotpEnrolmentFunctionalTest extends WebTestCase
{
    use AssertsSessionIdRotation;
    use AuthenticatesFunctionalRequests;
    use SeedsAnOrganizationMember;

    /** A dedicated identity, so enabling its factor cannot disturb the shared functional users. */
    private const string EMAIL = 'totp-enrolment@erpify.test';

    private const string CURRENT_PASSWORD = 'functional-password';

    private const string ENROL_ENDPOINT = '/api/v1/me/second-factor/totp';

    private const string CONFIRM_ENDPOINT = '/api/v1/me/second-factor/totp/confirm';

    private const string LOGIN_ENDPOINT = '/api/v1/backoffice/login';

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
        $this->assertStringContainsString(
            'no-store',
            (string) $client->getResponse()->headers->get('Cache-Control'),
            'the one response carrying the secret must not be kept by any cache',
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
        $this->assertSame(1, $this->activeSessionCount(), 'the caller holds a live session before the activation');

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
        // The activation revokes every session it finds, as a password change does. This harness seats a session
        // with no membership, so the re-login of the asking device cannot mint a new one here; the rotation of
        // a real login is pinned by the test below.
        $this->assertSame(0, $this->activeSessionCount(), 'the activation revokes every session it found');
        self::assertEmailCount(1);
        $mail = self::getMailerMessage();
        $this->assertNotNull($mail);
        $this->assertStringNotContainsString($secret, $mail->toString(), 'the notice carries nothing exercisable');

        // Once enabled, no route shows the secret again: a second enrolment is refused rather than re-issued.
        $this->authenticateAs($client, self::EMAIL, [Role::VIEWER->value]);
        $this->post($client, self::ENROL_ENDPOINT, ['currentPassword' => self::CURRENT_PASSWORD]);

        $this->assertSame(
            Response::HTTP_CONFLICT,
            $client->getResponse()->getStatusCode(),
            (string) $client->getResponse()->getContent(),
        );
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
        $this->assertSame(1, $this->activeSessionCount(), 'a refused confirmation revokes no session');
        self::assertEmailCount(0);
    }

    /**
     * The code budget is spent by the controller BEFORE the use case: once ten codes are spent, an eleventh request
     * carrying the CORRECT code is refused 429 and the factor stays pending, which no use-case-level count could
     * produce. A body the mapper refuses (422) spends nothing, since mapping runs before the action. Under the
     * budget, the enrolment's own cap closes it at the fifth wrong code.
     */
    public function testTheCodeBudgetIsSpentBeforeTheUseCaseAndAMalformedBodySpendsNothing(): void
    {
        $client = self::createClient();
        $client->disableReboot();
        $this->purgeIdentity();
        $this->authenticateAs($client, self::EMAIL, [Role::VIEWER->value]);
        $this->post($client, self::ENROL_ENDPOINT, ['currentPassword' => self::CURRENT_PASSWORD]);
        $secret = $this->jsonBody($client)['secret'] ?? null;
        $this->assertIsString($secret);

        $this->post($client, self::CONFIRM_ENDPOINT, ['code' => '']);
        $this->assertSame(
            Response::HTTP_UNPROCESSABLE_ENTITY,
            $client->getResponse()->getStatusCode(),
            (string) $client->getResponse()->getContent(),
        );

        // The first five wrong codes are refused one by one; from the sixth on the enrolment itself is closed
        // (its cap is counted on the row, and commits although each request fails), so even the budget's later
        // requests meet the 409 rather than a code check.
        for ($attempt = 1; $attempt <= 10; ++$attempt) {
            $this->post($client, self::CONFIRM_ENDPOINT, ['code' => 'abcdef']);
            $this->assertSame(
                $attempt <= 5 ? Response::HTTP_FORBIDDEN : Response::HTTP_CONFLICT,
                $client->getResponse()->getStatusCode(),
                \sprintf('attempt %d of the budget: %s', $attempt, (string) $client->getResponse()->getContent()),
            );
        }

        $this->assertSame(5, $this->storedFailedConfirmAttempts(), 'the cap counts five and stops');

        $this->post($client, self::CONFIRM_ENDPOINT, ['code' => $this->currentCode($secret)]);

        $this->assertSame(
            Response::HTTP_TOO_MANY_REQUESTS,
            $client->getResponse()->getStatusCode(),
            (string) $client->getResponse()->getContent(),
        );
        $this->assertStringContainsString('rate-limited', (string) $client->getResponse()->getContent());
        $this->assertGreaterThan(0, (int) $client->getResponse()->headers->get('Retry-After'));
        $this->assertSame(['pending'], $this->storedFactorStates(), 'the use case never ran on the refused code');
    }

    public function testAPendingEnrolmentOlderThanTenMinutesCannotBeConfirmed(): void
    {
        $client = self::createClient();
        $this->purgeIdentity();
        $this->authenticateAs($client, self::EMAIL, [Role::VIEWER->value]);
        $this->post($client, self::ENROL_ENDPOINT, ['currentPassword' => self::CURRENT_PASSWORD]);
        $secret = $this->jsonBody($client)['secret'] ?? null;
        $this->assertIsString($secret);
        $aged = $this->functionalConnection()->executeStatement(
            "UPDATE identity_totp_factor SET enrolment_started_at = enrolment_started_at - INTERVAL '10 minutes' "
            . 'WHERE user_id = CAST(:id AS uuid)',
            ['id' => $this->identityId()],
        );
        $this->assertSame(1, $aged, 'the enrolment must really have been aged');

        $this->post($client, self::CONFIRM_ENDPOINT, ['code' => $this->currentCode($secret)]);

        $this->assertSame(
            Response::HTTP_CONFLICT,
            $client->getResponse()->getStatusCode(),
            (string) $client->getResponse()->getContent(),
        );
        $this->assertStringContainsString('second-factor-not-enrolled', (string) $client->getResponse()->getContent());
        $this->assertSame(['pending'], $this->storedFactorStates());

        // A new enrolment restarts it, with a new secret and a fresh window.
        $this->post($client, self::ENROL_ENDPOINT, ['currentPassword' => self::CURRENT_PASSWORD]);
        $this->assertSame(
            Response::HTTP_CREATED,
            $client->getResponse()->getStatusCode(),
            (string) $client->getResponse()->getContent(),
        );
        $restarted = $this->jsonBody($client)['secret'] ?? null;
        $this->assertIsString($restarted);
        $this->assertNotSame($secret, $restarted);

        $this->post($client, self::CONFIRM_ENDPOINT, ['code' => $this->currentCode($restarted)]);
        $this->assertSame(
            Response::HTTP_OK,
            $client->getResponse()->getStatusCode(),
            (string) $client->getResponse()->getContent(),
        );
    }

    #[TestDox('A sealed secret that no longer authenticates answers the 500 integrity failure at the route')]
    public function testASealedSecretThatNoLongerOpensIsTheIntegrityFailure(): void
    {
        $client = self::createClient();
        $this->purgeIdentity();
        $this->authenticateAs($client, self::EMAIL, [Role::VIEWER->value]);
        $this->post($client, self::ENROL_ENDPOINT, ['currentPassword' => self::CURRENT_PASSWORD]);
        $secret = $this->jsonBody($client)['secret'] ?? null;
        $this->assertIsString($secret);
        $tampered = $this->functionalConnection()->executeStatement(
            'UPDATE identity_totp_factor SET sealed_secret = :tampered WHERE user_id = CAST(:id AS uuid)',
            ['tampered' => \str_repeat('A', 60), 'id' => $this->identityId()],
        );
        $this->assertSame(1, $tampered, 'the ciphertext must really have been altered');

        $this->post($client, self::CONFIRM_ENDPOINT, ['code' => $this->currentCode($secret)]);

        $this->assertSame(
            Response::HTTP_INTERNAL_SERVER_ERROR,
            $client->getResponse()->getStatusCode(),
            (string) $client->getResponse()->getContent(),
        );
        $this->assertStringContainsString(
            'second-factor-integrity-failure',
            (string) $client->getResponse()->getContent(),
        );
        $this->assertSame(['pending'], $this->storedFactorStates());
    }

    /**
     * The activation re-authenticates the device it was asked from, so the session id that device held must not
     * carry on: signed in through the real `json_login` and seeded as an organization member, because the re-login
     * can only mint a session for an identity with a membership.
     */
    public function testTheActivatingDeviceIsSignedBackInUnderASessionIdItDidNotHold(): void
    {
        $client = self::createClient();
        $client->disableReboot();

        $userId = Uuid::generate();
        $email = \sprintf('totp-rotation-%s@erpify.test', \str_replace('-', '', $userId));
        $this->seedAnOrganizationMember($userId, $email, self::CURRENT_PASSWORD);

        try {
            $client->request(
                Request::METHOD_POST,
                self::LOGIN_ENDPOINT,
                server: ['CONTENT_TYPE' => 'application/json', 'HTTP_ORIGIN' => 'http://localhost'],
                content: (string) \json_encode(['email' => $email, 'password' => self::CURRENT_PASSWORD]),
            );
            $this->assertTrue($client->getResponse()->isSuccessful(), (string) $client->getResponse()->getContent());

            $this->post($client, self::ENROL_ENDPOINT, ['currentPassword' => self::CURRENT_PASSWORD]);
            $this->assertSame(
                Response::HTTP_CREATED,
                $client->getResponse()->getStatusCode(),
                (string) $client->getResponse()->getContent(),
            );
            $secret = $this->jsonBody($client)['secret'] ?? null;
            $this->assertIsString($secret);
            $held = $this->sessionIdHeldBy($client);

            $this->post($client, self::CONFIRM_ENDPOINT, ['code' => $this->currentCode($secret)]);

            $this->assertSame(
                Response::HTTP_OK,
                $client->getResponse()->getStatusCode(),
                (string) $client->getResponse()->getContent(),
            );
            $this->assertSessionIdRotatedAwayFrom($client, $held);
        } finally {
            $connection = $this->functionalConnection();
            $connection->executeStatement(
                'DELETE FROM identity_totp_factor WHERE user_id = CAST(:id AS uuid)',
                ['id' => $userId],
            );
            $connection->executeStatement(
                'DELETE FROM iam_session WHERE user_id = CAST(:id AS uuid)',
                ['id' => $userId],
            );
            $this->forgetTheOrganizationMember($userId, $email);
        }
    }

    public function testConfirmingWithNoEnrolmentIsAConflict(): void
    {
        $client = self::createClient();
        $this->purgeIdentity();
        $this->authenticateAs($client, self::EMAIL, [Role::VIEWER->value]);

        $this->post($client, self::CONFIRM_ENDPOINT, ['code' => '123456']);

        $this->assertSame(
            Response::HTTP_CONFLICT,
            $client->getResponse()->getStatusCode(),
            (string) $client->getResponse()->getContent(),
        );
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
        $this->assertSame(
            Response::HTTP_UNPROCESSABLE_ENTITY,
            $client->getResponse()->getStatusCode(),
            (string) $client->getResponse()->getContent(),
        );
        $this->assertSame([], $this->storedFactorStates());

        $this->post($client, self::CONFIRM_ENDPOINT, ['code' => '123456', 'force' => 'yes']);
        $this->assertSame(
            Response::HTTP_UNPROCESSABLE_ENTITY,
            $client->getResponse()->getStatusCode(),
            (string) $client->getResponse()->getContent(),
        );
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
        $clock = self::getContainer()->get(Clock::class);
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
