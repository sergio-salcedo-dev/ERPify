<?php

declare(strict_types=1);

namespace Erpify\Tests\Functional\Iam\Identity;

use Erpify\Iam\Identity\Infrastructure\Controller\RevokeMyRecoverySecretController;
use Erpify\Iam\Identity\Infrastructure\Http\RevokeRecoverySecretRequest;
use Erpify\Shared\Access\Domain\Role;
use Erpify\Tests\Functional\AuthenticatesFunctionalRequests;
use PHPUnit\Framework\Attributes\CoversClass;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * The WIRING of the revocation endpoint: that this URL, at this method, resolves to this controller, and that
 * the strict payload resolver really runs on the way in.
 *
 * Everything below the wire is already pinned elsewhere and is deliberately not repeated here — the throttle
 * running before the use case, the credential proof running before the secret's existence is consulted, the
 * lock order over the two tables and the idempotence of a revocation with nothing to revoke each have a test
 * at the level where the property lives. None of them touches the router: the controller unit test builds the
 * class by hand and calls `__invoke`, so a route path, an HTTP method or a payload attribute could be anything
 * at all and every one of those tests would stay green. That is the whole gap this file closes, which is why
 * it asserts the coarse outcome of each request and nothing finer.
 *
 * **`WebTestCase` rather than Behat**, for the reason the sibling `/me/password` test states: Behat drives real
 * HTTP but contributes nothing to the coverage report, so a controller reached only from a feature file reads
 * as uncovered production code. Here the request travels the real router, the real firewall and the real
 * argument resolvers, and the classes it passes through are credited.
 *
 * **Every test seeds a secret and asserts it is there before asserting it is gone.** A test whose subject is an
 * absence is satisfied by a seed that inserted nothing, so the intermediate count is the only thing separating
 * "the revocation destroyed the row" from "there was never a row".
 *
 * @internal
 */
#[CoversClass(RevokeMyRecoverySecretController::class)]
#[CoversClass(RevokeRecoverySecretRequest::class)]
final class RevokeMyRecoverySecretFunctionalTest extends WebTestCase
{
    use AuthenticatesFunctionalRequests;

    /** A dedicated identity, so destroying its recovery secret cannot disturb the shared functional users. */
    private const string EMAIL = 'revoke-my-recovery-secret@erpify.test';

    private const string CURRENT_PASSWORD = 'functional-password';

    private const string ENDPOINT = '/api/v1/me/recovery-secret/revoke';

    /**
     * The seeded row's primary key, which is also the selector half of a presented secret. Fixed rather than
     * drawn so {@see purgeIdentity} can sweep it even when the identity that owned it is already gone.
     */
    private const string SECRET_SELECTOR = '0190f400-0000-7000-8000-0000000000b1';

    /**
     * A placeholder digest. Revocation destroys the row without ever verifying a presented secret against it,
     * so no value here could make this test pass or fail — only its presence in the table matters.
     */
    private const string SECRET_HASH = '1eb66bb65a0c3db8c8bc00d65db18d26f8a7aba9e7ed643bbc2bf8ba63b6d70b';

    public function testTheRouteAnswersAProvenRevocationWithNoContentAndDestroysTheSecret(): void
    {
        $client = self::createClient();
        $this->purgeIdentity();
        $this->authenticateAs($client, self::EMAIL, [Role::VIEWER->value]);
        $this->seedRecoverySecret();

        $this->assertSame(1, $this->storedSecretCount(), 'the seed must put the row this test is about to negate');

        $this->postRevoke($client, ['currentPassword' => self::CURRENT_PASSWORD]);

        $this->assertSame(
            Response::HTTP_NO_CONTENT,
            $client->getResponse()->getStatusCode(),
            (string) $client->getResponse()->getContent(),
        );
        $this->assertSame(0, $this->storedSecretCount(), 'the secret survived a revocation that reported success');
    }

    /**
     * The same request as above with one value changed, which is what makes the 403 attributable: the route,
     * the session and the body are identical, so only the credential can account for the different answer.
     */
    public function testAWrongCurrentPasswordIsRefusedAndTheSecretSurvives(): void
    {
        $client = self::createClient();
        $this->purgeIdentity();
        $this->authenticateAs($client, self::EMAIL, [Role::VIEWER->value]);
        $this->seedRecoverySecret();

        $this->assertSame(1, $this->storedSecretCount(), 'the seed must put the row this refusal has to preserve');

        $this->postRevoke($client, ['currentPassword' => 'not-the-current-password']);

        $this->assertSame(
            Response::HTTP_FORBIDDEN,
            $client->getResponse()->getStatusCode(),
            (string) $client->getResponse()->getContent(),
        );
        $this->assertSame(1, $this->storedSecretCount(), 'a refused attempt must leave the secret standing');
    }

    /**
     * A body member the payload does not declare is refused before the use case runs, which is the strict
     * resolver's answer arriving on THIS route.
     *
     * The source gate over `#[MapRequestPayload]` forbids the permissive attribute; it never requires that a
     * payload attribute be there at all, so a parameter carrying none passes it — and passes the controller
     * unit test too, which builds the argument itself and never resolves one. Measured across the whole
     * suite, deleting the attribute from the parameter reds this class and nothing else.
     */
    public function testABodyMemberTheEndpointDoesNotDeclareIsRefused(): void
    {
        $client = self::createClient();
        $this->purgeIdentity();
        $this->authenticateAs($client, self::EMAIL, [Role::VIEWER->value]);
        $this->seedRecoverySecret();

        $this->assertSame(1, $this->storedSecretCount(), 'the seed must put the row a mapped request would destroy');

        $this->postRevoke($client, [
            'currentPassword' => self::CURRENT_PASSWORD,
            'confirm' => 'yes',
        ]);

        $this->assertSame(
            Response::HTTP_UNPROCESSABLE_ENTITY,
            $client->getResponse()->getStatusCode(),
            (string) $client->getResponse()->getContent(),
        );
        $this->assertStringContainsString(
            'validation-failed',
            (string) $client->getResponse()->getContent(),
        );
        $this->assertSame(1, $this->storedSecretCount(), 'a refused body must not reach the use case at all');
    }

    /**
     * This suite shares one long-lived database and does not roll back, so an identity left behind by an
     * earlier run carries that run's state — and a surviving recovery secret would let the count assertions
     * read a row this test never seeded. Deleting the identity makes {@see authenticateAs} recreate it with a
     * known credential and a known id, which is what makes these tests repeatable rather than green once.
     *
     * The secret row is swept by its own key first, because it outlives its owner: nothing in the schema
     * references `identity_user`, so a run that dropped the identity without it would leave an orphan the
     * seed then collides with on the primary key.
     */
    private function purgeIdentity(): void
    {
        $connection = $this->functionalConnection();

        $connection->executeStatement(
            'DELETE FROM identity_recovery_secret WHERE id = CAST(:id AS uuid)',
            ['id' => self::SECRET_SELECTOR],
        );

        $id = $connection->fetchOne('SELECT id FROM identity_user WHERE email = :email', ['email' => self::EMAIL]);

        if (!\is_string($id)) {
            return;
        }

        $connection->executeStatement(
            'DELETE FROM identity_recovery_secret WHERE user_id = CAST(:id AS uuid)',
            ['id' => $id],
        );
        $connection->executeStatement('DELETE FROM iam_session WHERE user_id = CAST(:id AS uuid)', ['id' => $id]);
        $connection->executeStatement('DELETE FROM identity_user WHERE id = CAST(:id AS uuid)', ['id' => $id]);
    }

    private function seedRecoverySecret(): void
    {
        $inserted = $this->functionalConnection()->executeStatement(
            'INSERT INTO identity_recovery_secret (id, user_id, secret_hash, expires_at, created_at, updated_at) '
            . "VALUES (CAST(:id AS uuid), CAST(:userId AS uuid), :hash, '2099-01-01 00:00:00', NOW(), NOW())",
            ['id' => self::SECRET_SELECTOR, 'userId' => $this->identityId(), 'hash' => self::SECRET_HASH],
        );

        $this->assertSame(1, $inserted, 'the seed inserted no row, so nothing after it means anything');
    }

    /**
     * @param array<string, string> $body
     */
    private function postRevoke(KernelBrowser $client, array $body): void
    {
        $client->request(
            Request::METHOD_POST,
            self::ENDPOINT,
            server: ['CONTENT_TYPE' => 'application/json'],
            content: (string) \json_encode($body),
        );
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

    private function storedSecretCount(): int
    {
        $count = $this->functionalConnection()->fetchOne(
            'SELECT count(*) FROM identity_recovery_secret WHERE user_id = CAST(:id AS uuid)',
            ['id' => $this->identityId()],
        );
        $this->assertIsNumeric($count);

        return (int) $count;
    }
}
