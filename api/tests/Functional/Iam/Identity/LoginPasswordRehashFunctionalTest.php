<?php

declare(strict_types=1);

namespace Erpify\Tests\Functional\Iam\Identity;

use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Erpify\Iam\Identity\Application\RehashPasswordBestEffort;
use Erpify\Iam\Identity\Infrastructure\Persistence\Doctrine\DoctrineUserRepository;
use Erpify\Iam\Identity\Infrastructure\Security\SecurityUser;
use Erpify\Iam\Identity\Infrastructure\Security\UserProvider;
use Erpify\Shared\Uuid\Domain\Uuid;
use Erpify\Tests\Functional\ResolvesContainerServices;
use Erpify\Tests\Functional\SeedsAnOrganizationMember;
use LogicException;
use Override;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\PasswordHasher\Hasher\PasswordHasherFactoryInterface;
use Symfony\Component\PasswordHasher\PasswordHasherInterface;

/**
 * A credential stored under parameters the configured hasher has moved past is re-encoded by the first login
 * that proves it — driven through the real `json_login` firewall against real Postgres, because the upgrade is
 * wired by the framework (`PasswordMigratingListener` on `LoginSuccessEvent`, fed by the badge `json_login`
 * attaches only to a provider implementing `PasswordUpgraderInterface`) and no unit test reaches that seam.
 *
 * The test environment configures bcrypt at cost 4, the algorithm's floor, so "older and weaker" cannot be
 * staged literally here; what the upgrade keys on is `needsRehash()`, which is `password_needs_rehash()` for
 * bcrypt and answers true for ANY cost other than the configured one. A cost-5 hash therefore exercises the
 * exact branch a production cost-10 hash takes under a cost-13 configuration, and an argon2id hash exercises
 * the other reason `auto` gives — an algorithm it verifies but no longer mints.
 *
 * What a green here proves beyond "the bytes moved": the new hash verifies the same password and needs no
 * further rehash; the upgrade announced nothing (no event row for the identity, no mail); it left `updated_at`
 * alone; the login still minted exactly one session; and that session survives its next request, read
 * against the row — the session's copy of the identity is compared on the CREDENTIAL against the reloaded row
 * on every request, so a rehash the row and the session disagreed about would sign the user out one request
 * after signing them in. A negative control moves the row under the same session and watches it signed out.
 *
 * @internal
 */
#[CoversClass(UserProvider::class)]
#[CoversClass(RehashPasswordBestEffort::class)]
#[CoversClass(DoctrineUserRepository::class)]
final class LoginPasswordRehashFunctionalTest extends WebTestCase
{
    use ResolvesContainerServices;
    use SeedsAnOrganizationMember;

    private const string LOGIN_PATH = '/api/v1/backoffice/login';

    private const string ME_PATH = '/api/v1/me';

    private const string ORIGIN = 'http://localhost';

    private const string PASSWORD = 'the-password-whose-hash-is-upgraded';

    private const string LEGACY_BCRYPT = 'bcrypt at a cost the configuration does not name';

    private const string LEGACY_ARGON2ID = 'argon2id, verified by auto but no longer minted by it';

    /** Name of the trigger and of its function, dropped in teardown whether or not a test created them. */
    private const string FAULT = 'login_password_rehash_functional_fault';

    private KernelBrowser $client;

    private Connection $connection;

    private string $userId = '';

    private string $email = '';

    #[Override]
    protected function setUp(): void
    {
        $this->client = self::createClient();
        $this->client->disableReboot();

        $this->connection = $this->service(EntityManagerInterface::class)->getConnection();

        $this->userId = Uuid::generate();
        $this->email = \sprintf('rehash-%s@erpify.test', \str_replace('-', '', $this->userId));
    }

    protected function tearDown(): void
    {
        $this->connection->executeStatement(\sprintf('DROP TRIGGER IF EXISTS %s ON identity_user', self::FAULT));
        $this->connection->executeStatement(\sprintf('DROP FUNCTION IF EXISTS %s()', self::FAULT));

        if ('' !== $this->userId) {
            $this->connection->executeStatement(
                'DELETE FROM iam_session WHERE user_id = CAST(:id AS uuid)',
                ['id' => $this->userId],
            );
            $this->forgetTheOrganizationMember($this->userId, $this->email);
        }

        parent::tearDown();
    }

    #[DataProvider('provideALoginUpgradesALegacyHashAndAnnouncesNothingCases')]
    public function testALoginUpgradesALegacyHashAndAnnouncesNothing(
        string $legacyShape,
    ): void {
        $legacy = $this->legacyHash($legacyShape);
        $this->seedIdentityHolding($legacy);

        $hasher = $this->hasher();
        $this->assertTrue($hasher->needsRehash($legacy), 'the arrangement must hold a hash the hasher would upgrade');
        $this->assertTrue($hasher->verify($legacy, self::PASSWORD), 'and one that still verifies the password');

        $updatedAtBefore = $this->updatedAt();
        $eventsBefore = $this->eventCountForIdentity();

        $this->login(self::PASSWORD);

        $this->assertTrue(
            $this->client->getResponse()->isSuccessful(),
            (string) $this->client->getResponse()->getContent(),
        );

        $stored = $this->storedHash();
        $this->assertNotSame($legacy, $stored, 'the login must have replaced the legacy encoding');
        $this->assertStringStartsWith('$2y$04$', $stored, 'bcrypt at the cost the test environment configures');
        $this->assertFalse($hasher->needsRehash($stored), 'the stored hash is current for the configured hasher');
        $this->assertTrue($hasher->verify($stored, self::PASSWORD), 'and still encodes the same password');

        $this->assertSame($updatedAtBefore, $this->updatedAt(), 'a re-encoding is not a change to the identity');
        $this->assertSame($eventsBefore, $this->eventCountForIdentity(), 'no fact about the identity was recorded');
        self::assertEmailCount(0);
        $this->assertSame(1, $this->activeSessionCount(), 'the login minted its session and nothing revoked it');

        $this->assertSame(
            Response::HTTP_OK,
            $this->nextRequestStatus(),
            'the session must survive its next request: its copy of the credential has to match the row',
        );

        // The control that makes the line above mean "matches the ROW": moved underneath it, the same session is
        // signed out — so the survival is the credential comparison passing, not the comparison never running.
        $this->connection->executeStatement(
            'UPDATE identity_user SET password_hash = :hash WHERE id = CAST(:id AS uuid)',
            ['hash' => $this->legacyHash(self::LEGACY_BCRYPT), 'id' => $this->userId],
        );
        $this->assertSame(Response::HTTP_UNAUTHORIZED, $this->nextRequestStatus());
    }

    /**
     * @return iterable<string, array{0: string}>
     */
    public static function provideALoginUpgradesALegacyHashAndAnnouncesNothingCases(): iterable
    {
        yield self::LEGACY_BCRYPT => [self::LEGACY_BCRYPT];
        yield self::LEGACY_ARGON2ID => [self::LEGACY_ARGON2ID];
    }

    /**
     * The best-effort contract driven through real Doctrine rather than an inline transaction: a failed
     * statement closes the entity manager mid-`LoginSuccessEvent`, the transaction manager resets it, and the
     * listeners after the upgrade — the lockout clear and the session mint — run on the replacement. The fault
     * is a real one, raised by Postgres from a trigger scoped to this identity's credential column.
     */
    public function testAStoreFaultDuringTheUpgradeStillAdmitsTheLoginAndLeavesTheLegacyHash(): void
    {
        $legacy = $this->legacyHash(self::LEGACY_BCRYPT);
        $this->seedIdentityHolding($legacy);
        $this->connection->executeStatement(
            'UPDATE identity_user SET failed_attempts = 3 WHERE id = CAST(:id AS uuid)',
            ['id' => $this->userId],
        );
        $this->refuseCredentialWrites();

        $this->login(self::PASSWORD);

        $this->assertTrue(
            $this->client->getResponse()->isSuccessful(),
            (string) $this->client->getResponse()->getContent(),
        );
        $this->assertSame($legacy, $this->storedHash(), 'the refused write left the verified hash in place');
        $failedAttempts = $this->connection->fetchOne(
            'SELECT failed_attempts FROM identity_user WHERE id = CAST(:id AS uuid)',
            ['id' => $this->userId],
        );
        $this->assertIsNumeric($failedAttempts);
        $this->assertSame(0, (int) $failedAttempts, 'the lockout clear still ran after the upgrade failed');
        $this->assertSame(1, $this->activeSessionCount(), 'and the session was still minted');
        $this->assertSame(Response::HTTP_OK, $this->nextRequestStatus(), 'and it agrees with the row it left');
    }

    public function testAFailedLoginNeverUpgradesTheStoredHash(): void
    {
        $legacy = $this->legacyHash(self::LEGACY_BCRYPT);
        $this->seedIdentityHolding($legacy);

        $this->login('not-the-password');

        $this->assertSame(
            Response::HTTP_UNAUTHORIZED,
            $this->client->getResponse()->getStatusCode(),
            (string) $this->client->getResponse()->getContent(),
        );
        $this->assertSame($legacy, $this->storedHash(), 'only a verified credential may be re-encoded');
    }

    public function testACurrentHashIsLeftByteForByteAlone(): void
    {
        $current = $this->hasher()->hash(self::PASSWORD);
        $this->seedIdentityHolding($current);

        $this->login(self::PASSWORD);

        $this->assertTrue($this->client->getResponse()->isSuccessful());
        $this->assertSame($current, $this->storedHash(), 'a hash that needs no rehash is never rewritten');
    }

    private function legacyHash(string $shape): string
    {
        return match ($shape) {
            self::LEGACY_BCRYPT => \password_hash(self::PASSWORD, PASSWORD_BCRYPT, ['cost' => 5]),
            self::LEGACY_ARGON2ID => \sodium_crypto_pwhash_str(
                self::PASSWORD,
                SODIUM_CRYPTO_PWHASH_OPSLIMIT_INTERACTIVE,
                SODIUM_CRYPTO_PWHASH_MEMLIMIT_INTERACTIVE,
            ),
            default => throw new LogicException(\sprintf('Unknown legacy hash shape "%s".', $shape)),
        };
    }

    /**
     * A real organization member, because establishing a session resolves the caller's organization — then the
     * stored credential is overwritten in place, since the seed mints its own at the configured cost and the
     * point of the arrangement is a hash the configuration did not mint.
     */
    private function seedIdentityHolding(string $hash): void
    {
        $this->seedAnOrganizationMember($this->userId, $this->email, self::PASSWORD);
        $this->connection->executeStatement(
            'UPDATE identity_user SET password_hash = :hash WHERE id = CAST(:id AS uuid)',
            ['hash' => $hash, 'id' => $this->userId],
        );
        // The seed re-read the identity into the manager the request will use; left there, the login would
        // authenticate against that cached copy and never see the hash written underneath it.
        $this->service(EntityManagerInterface::class)->clear();
    }

    /**
     * The request after the login, read against the ROW: the manager is cleared first, because under a
     * non-rebooting client the reload would otherwise be served from the identity map the login left behind.
     */
    private function nextRequestStatus(): int
    {
        $this->service(EntityManagerInterface::class)->clear();
        $this->client->request(Request::METHOD_GET, self::ME_PATH, server: ['HTTP_ACCEPT' => 'application/json']);

        return $this->client->getResponse()->getStatusCode();
    }

    private function refuseCredentialWrites(): void
    {
        $this->connection->executeStatement(
            'CREATE FUNCTION ' . self::FAULT . '() RETURNS trigger LANGUAGE plpgsql AS $body$ BEGIN'
            . ' RAISE EXCEPTION $msg$credential write refused by the test$msg$; END $body$',
        );
        $this->connection->executeStatement(
            'CREATE TRIGGER ' . self::FAULT . ' BEFORE UPDATE OF password_hash ON identity_user FOR EACH ROW'
            . " WHEN (NEW.id = '" . $this->userId . "'::uuid) EXECUTE FUNCTION " . self::FAULT . '()',
        );
    }

    private function hasher(): PasswordHasherInterface
    {
        return $this->service(PasswordHasherFactoryInterface::class)->getPasswordHasher(SecurityUser::class);
    }

    private function login(string $password): void
    {
        $this->client->request(
            Request::METHOD_POST,
            self::LOGIN_PATH,
            server: [
                'CONTENT_TYPE' => 'application/json',
                'HTTP_ACCEPT' => 'application/json',
                'HTTP_ORIGIN' => self::ORIGIN,
            ],
            content: (string) \json_encode(['email' => $this->email, 'password' => $password]),
        );
    }

    private function storedHash(): string
    {
        $hash = $this->connection->fetchOne(
            'SELECT password_hash FROM identity_user WHERE id = CAST(:id AS uuid)',
            ['id' => $this->userId],
        );
        $this->assertIsString($hash);

        return $hash;
    }

    private function updatedAt(): string
    {
        $updatedAt = $this->connection->fetchOne(
            'SELECT updated_at FROM identity_user WHERE id = CAST(:id AS uuid)',
            ['id' => $this->userId],
        );
        $this->assertIsString($updatedAt);

        return $updatedAt;
    }

    private function eventCountForIdentity(): int
    {
        $count = $this->connection->fetchOne(
            'SELECT count(*) FROM event_store WHERE aggregate_id = CAST(:id AS uuid)',
            ['id' => $this->userId],
        );
        $this->assertIsNumeric($count);

        return (int) $count;
    }

    private function activeSessionCount(): int
    {
        $count = $this->connection->fetchOne(
            "SELECT count(*) FROM iam_session WHERE user_id = CAST(:id AS uuid) AND status = 'ACTIVE'",
            ['id' => $this->userId],
        );
        $this->assertIsNumeric($count);

        return (int) $count;
    }
}
