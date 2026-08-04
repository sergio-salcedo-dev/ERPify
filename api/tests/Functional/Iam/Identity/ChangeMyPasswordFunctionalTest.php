<?php

declare(strict_types=1);

namespace Erpify\Tests\Functional\Iam\Identity;

use Erpify\Iam\Identity\Infrastructure\Controller\ChangeMyPasswordController;
use Erpify\Iam\Identity\Infrastructure\Http\ChangeMyPasswordRequest;
use Erpify\Iam\Identity\Infrastructure\Security\PasswordHasher;
use Erpify\Iam\Identity\Infrastructure\Security\ReauthenticateDevice;
use Erpify\Shared\Access\Domain\Role;
use Erpify\Tests\Functional\AuthenticatesFunctionalRequests;
use PHPUnit\Framework\Attributes\CoversClass;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * The credential change driven over a real request through the real firewall, which is the only way the
 * controller is executed at all: the unit suite reaches the use case with in-memory doubles, and the step
 * after the commit is the framework's own programmatic login, which needs a resolved firewall.
 *
 * What this class deliberately does NOT assert is the freshly minted session. Under `KernelBrowser` the
 * programmatic login runs but its listeners do not, so no session is minted here whatever the store does —
 * measured, not assumed: a fixture that refused every write recorded zero refusals, which means an
 * assertion of "no session survives" would have been satisfied by a mint that never ran. That is a vacuous
 * green, so it was removed rather than kept. The mint, and the containment when it fails, belong to
 * `features/backoffice/identity/change_password.feature`, which drives real HTTP — at the cost that Behat
 * contributes nothing to the coverage report.
 *
 * @internal
 */
#[CoversClass(ChangeMyPasswordController::class)]
#[CoversClass(ChangeMyPasswordRequest::class)]
#[CoversClass(ReauthenticateDevice::class)]
#[CoversClass(PasswordHasher::class)]
final class ChangeMyPasswordFunctionalTest extends WebTestCase
{
    use AuthenticatesFunctionalRequests;

    /** A dedicated identity, so replacing its credential cannot disturb the shared functional users. */
    private const string EMAIL = 'change-my-password@erpify.test';

    private const string CURRENT_PASSWORD = 'functional-password';

    private const string NEW_PASSWORD = 'a-brand-new-strong-password';

    private const string ENDPOINT = '/api/v1/me/password';

    public function testItReplacesTheCredentialAndRevokesTheSessionThatAskedForIt(): void
    {
        $client = self::createClient();
        $this->purgeIdentity();
        $this->authenticateAs($client, self::EMAIL, [Role::VIEWER->value]);

        $before = $this->storedHash();
        // Without this the revocation assertion below could pass over an arrangement that seated nothing.
        $this->assertSame(1, $this->activeSessionCount(), 'the caller must start with a live session');

        $this->postChange($client);

        $this->assertSame(
            Response::HTTP_NO_CONTENT,
            $client->getResponse()->getStatusCode(),
            (string) $client->getResponse()->getContent(),
        );
        $this->assertNotSame($before, $this->storedHash(), 'the stored credential must actually have moved');
        $this->assertSame(0, $this->activeSessionCount(), 'the change revokes every session it found');
    }

    public function testAWrongCurrentPasswordIsRefusedWithoutTouchingTheCredential(): void
    {
        $client = self::createClient();
        $this->purgeIdentity();
        $this->authenticateAs($client, self::EMAIL, [Role::VIEWER->value]);

        $before = $this->storedHash();

        $this->postChange($client, current: 'not-the-current-password');

        $this->assertSame(
            Response::HTTP_FORBIDDEN,
            $client->getResponse()->getStatusCode(),
            (string) $client->getResponse()->getContent(),
        );
        $this->assertStringContainsString(
            'invalid-current-password',
            (string) $client->getResponse()->getContent(),
        );
        $this->assertSame($before, $this->storedHash(), 'a refused attempt must leave the credential alone');
        $this->assertSame(1, $this->activeSessionCount(), 'and must not evict the caller it refused');
    }

    /**
     * This suite shares one database and does not roll back, so an identity left behind by an earlier run
     * carries the credential that run replaced — and the test would then submit a current password that is no
     * longer current. Deleting it makes {@see authenticateAs} recreate the row with a known credential, which
     * is what makes these tests repeatable rather than green exactly once.
     */
    private function purgeIdentity(): void
    {
        $connection = $this->functionalConnection();

        $id = $connection->fetchOne('SELECT id FROM identity_user WHERE email = :email', ['email' => self::EMAIL]);

        if (!\is_string($id)) {
            return;
        }

        $connection->executeStatement('DELETE FROM iam_session WHERE user_id = CAST(:id AS uuid)', ['id' => $id]);
        $connection->executeStatement('DELETE FROM identity_user WHERE id = CAST(:id AS uuid)', ['id' => $id]);
    }

    private function postChange(KernelBrowser $client, string $current = self::CURRENT_PASSWORD): void
    {
        $client->request(
            Request::METHOD_POST,
            self::ENDPOINT,
            server: ['CONTENT_TYPE' => 'application/json'],
            content: (string) \json_encode([
                'currentPassword' => $current,
                'newPassword' => self::NEW_PASSWORD,
            ]),
        );
    }

    private function storedHash(): string
    {
        $hash = $this->functionalConnection()->fetchOne(
            'SELECT password_hash FROM identity_user WHERE email = :email',
            ['email' => self::EMAIL],
        );
        $this->assertIsString($hash);

        return $hash;
    }

    private function activeSessionCount(): int
    {
        $userId = $this->functionalConnection()->fetchOne(
            'SELECT id FROM identity_user WHERE email = :email',
            ['email' => self::EMAIL],
        );
        $this->assertIsString($userId);

        $count = $this->functionalConnection()->fetchOne(
            "SELECT count(*) FROM iam_session WHERE user_id = CAST(:id AS uuid) AND status = 'ACTIVE'",
            ['id' => $userId],
        );
        $this->assertIsNumeric($count);

        return (int) $count;
    }
}
