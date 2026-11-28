<?php

declare(strict_types=1);

namespace Erpify\Tests\Functional\Iam\Identity;

use Erpify\Iam\Identity\Infrastructure\Controller\ConfirmTotpFactorController;
use Erpify\Iam\Identity\Infrastructure\Controller\EnrolTotpFactorController;
use Erpify\Iam\Identity\Infrastructure\Http\ConfirmTotpFactorRequest;
use Erpify\Iam\Identity\Infrastructure\Http\EnrolTotpFactorRequest;
use Erpify\Shared\Access\Domain\Role;
use Erpify\Shared\Uuid\Domain\Uuid;
use Erpify\Tests\Functional\AssertsSessionIdRotation;
use Erpify\Tests\Functional\AuthenticatesFunctionalRequests;
use Erpify\Tests\Functional\SeedsAnOrganizationMember;
use PHPUnit\Framework\Attributes\CoversClass;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Mime\RawMessage;

/**
 * The WIRING of the two enrolment routes, through the real router, firewall, argument resolvers and database:
 * that a password re-proof starts a pending enrolment and shows its secret once, that a code computed from that
 * secret enables the factor, and that the coarse refusals arrive on these URLs. The order of the proof against
 * the factor lookup, the lock order and the window rules are pinned by the use-case and algorithm unit tests; the
 * non-`ACTIVE` walls by the use-case tests too, since the firewall drops a session whose identity changed status
 * before any route runs. A concurrent first enrolment (409 `concurrent-unique-write`) is not provoked here: both
 * requests lock the identity row first, so the second one finds the first's row and restarts it. The budget, the
 * cap, the window and the integrity failure of the confirmation are {@see TotpConfirmationLimitsFunctionalTest}.
 *
 * `WebTestCase` rather than Behat for the reason the sibling recovery-secret test gives: the classes the request
 * passes through are credited to the coverage report.
 *
 * Every test starts from a purged identity, because the suite shares a long-lived database that is never rolled
 * back, and a factor left by an earlier run would decide the 201/409 answers on its own.
 *
 * @internal
 *
 * @SuppressWarnings("PHPMD.CouplingBetweenObjects") the two routes, their payloads, the mail it sends and the
 *                                                   session harness
 */
#[CoversClass(EnrolTotpFactorController::class)]
#[CoversClass(ConfirmTotpFactorController::class)]
#[CoversClass(EnrolTotpFactorRequest::class)]
#[CoversClass(ConfirmTotpFactorRequest::class)]
final class TotpEnrolmentFunctionalTest extends WebTestCase
{
    use AssertsSessionIdRotation;
    use AuthenticatesFunctionalRequests;
    use DrivesTotpEnrolment;
    use SeedsAnOrganizationMember;

    /** A dedicated identity, so enabling its factor cannot disturb the shared functional users. */
    private const string EMAIL = 'totp-enrolment@erpify.test';

    private const string CURRENT_PASSWORD = 'functional-password';

    private const string LOGIN_ENDPOINT = '/api/v1/backoffice/login';

    public function testAProvenEnrolmentShowsTheSecretOnceAndAValidCodeEnablesTheFactor(): void
    {
        $client = self::createClient();
        $this->purgeIdentity();
        $this->authenticateAs($client, self::EMAIL, [Role::VIEWER->value]);

        $secret = $this->enrol($client);

        $provisioningUri = $this->jsonBody($client)['provisioningUri'] ?? null;
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

        $this->assertStatus($client, Response::HTTP_OK);
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
        $this->assertInstanceOf(RawMessage::class, $mail);
        $this->assertStringNotContainsString($secret, $mail->toString(), 'the notice carries nothing exercisable');

        // Once enabled, no route shows the secret again: a second enrolment is refused rather than re-issued.
        $this->authenticateAs($client, self::EMAIL, [Role::VIEWER->value]);
        $this->post($client, self::ENROL_ENDPOINT, ['currentPassword' => self::CURRENT_PASSWORD]);

        $this->assertStatus($client, Response::HTTP_CONFLICT);
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

        $this->assertStatus($client, Response::HTTP_FORBIDDEN);
        $this->assertStringContainsString('invalid-current-password', (string) $client->getResponse()->getContent());
        $this->assertSame([], $this->storedFactorStates());
    }

    public function testAWrongCodeIsRefusedAndTheFactorStaysPending(): void
    {
        $client = self::createClient();
        $this->purgeIdentity();
        $this->authenticateAs($client, self::EMAIL, [Role::VIEWER->value]);
        $secret = $this->enrol($client);

        $this->post($client, self::CONFIRM_ENDPOINT, ['code' => $this->wrongCode($secret)]);

        $this->assertStatus($client, Response::HTTP_FORBIDDEN);
        $this->assertStringContainsString('invalid-second-factor-code', (string) $client->getResponse()->getContent());
        $this->assertSame(['pending'], $this->storedFactorStates());
        $this->assertSame(1, $this->storedFailedConfirmAttempts(), 'the wrong code is counted on the enrolment');
        $this->assertSame(1, $this->activeSessionCount(), 'a refused confirmation revokes no session');
        self::assertEmailCount(0);
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

            $secret = $this->enrol($client);
            $held = $this->sessionIdHeldBy($client);

            $this->post($client, self::CONFIRM_ENDPOINT, ['code' => $this->currentCode($secret)]);

            $this->assertStatus($client, Response::HTTP_OK);
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

        $this->assertStatus($client, Response::HTTP_CONFLICT);
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
        $this->assertStatus($client, Response::HTTP_UNPROCESSABLE_ENTITY);
        $this->assertSame([], $this->storedFactorStates());

        $this->post($client, self::CONFIRM_ENDPOINT, ['code' => '123456', 'force' => 'yes']);
        $this->assertStatus($client, Response::HTTP_UNPROCESSABLE_ENTITY);
    }
}
