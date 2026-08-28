<?php

declare(strict_types=1);

namespace Erpify\Tests\Functional\Iam\Identity;

use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Erpify\Iam\Identity\Domain\Entity\User;
use Erpify\Iam\Identity\Domain\HashedPassword;
use Erpify\Iam\Identity\Domain\Repository\UserRepository;
use Erpify\Iam\Identity\Infrastructure\Security\PasswordHasher;
use Erpify\Iam\Identity\Infrastructure\Security\ProblemDetailsAuthenticationFailureHandler;
use Erpify\Iam\Identity\Infrastructure\Security\UserChecker;
use Erpify\Iam\Identity\Infrastructure\Security\UserProvider;
use Erpify\Shared\Access\Domain\Role;
use Erpify\Shared\Uuid\Domain\Uuid;
use Override;
use PHPUnit\Framework\Attributes\CoversClass;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * The three pre-identity login failures — a wrong password, an email no identity carries, and an `INVITED`
 * identity that never set a credential — must be ONE answer on the wire, because the whole point of the
 * uniform 401 is that a caller cannot tell which of them happened.
 *
 * Each of the three is already asserted apart, on the members someone thought to name (`status`, `type`,
 * `title`). Nothing compared the three answers to EACH OTHER, so a member outside that list — a `detail`, an
 * extension, a differing header, a reordered payload — could diverge with every one of those assertions still
 * green. This drives all three through the real firewall against real Postgres and compares whole responses.
 *
 * Two members are set aside, and only two. `instance` is minted per error occurrence, so it MUST differ (that
 * it differs is asserted). The `debug` extension exists to name the cause and is emitted under `dev`/`test`
 * only — `prod` omits it entirely — so comparing it would assert the opposite of its own contract; every other
 * member, and the order they are spelled in, is compared as a whole.
 *
 * Three identities' worth of setup against the real graph is inherent to driving the firewall end to end.
 *
 * @internal
 *
 * @SuppressWarnings("PHPMD.CouplingBetweenObjects")
 */
#[CoversClass(ProblemDetailsAuthenticationFailureHandler::class)]
#[CoversClass(UserChecker::class)]
#[CoversClass(UserProvider::class)]
final class LoginPreIdentityOpacityFunctionalTest extends WebTestCase
{
    private const string LOGIN_PATH = '/api/v1/backoffice/login';

    private const string ORIGIN = 'http://localhost';

    /**
     * A canonical lowercase UUIDv7, which is the only shape the correlation listener echoes back. Fixing it
     * pins the body's `correlation-id` across the three requests, so the one member left free to vary is
     * `instance` — and that one varies by contract rather than by accident.
     */
    private const string CORRELATION_ID = '0190a1de-0602-7abc-8def-000000000055';

    private const string INSTANCE_PLACEHOLDER = '<per-occurrence-instance>';

    /**
     * The one member whose whole job is to reveal the cause. It is emitted under `dev`/`test` and omitted in
     * `prod`, so on the deployed wire it cannot make the three answers differ — and holding it to sameness
     * here would be asserting against its contract rather than for it.
     */
    private const string CAUSE_NAMING_MEMBER = 'debug';

    private const string REGISTERED_PASSWORD = 'the-registered-password';

    private const string WRONG_PASSWORD = 'not-the-registered-password';

    private const string WRONG_PASSWORD_CASE = 'a wrong password on a registered identity';

    private const string UNKNOWN_EMAIL_CASE = 'an email no identity carries';

    private const string INVITED_CASE = 'an INVITED identity that never set a credential';

    private KernelBrowser $client;

    private Connection $connection;

    /** @var list<string> ids of the identities this test seeded, so teardown removes those rows and no others */
    private array $seededUserIds = [];

    private string $activeEmail = '';

    private string $invitedEmail = '';

    #[Override]
    protected function setUp(): void
    {
        $this->client = self::createClient();
        // Persistent kernel so the cached connection and container stay valid across the three requests one
        // test makes; the cookie jar is cleared per request so each login is a clean anonymous call.
        $this->client->disableReboot();

        $this->connection = $this->service(EntityManagerInterface::class)->getConnection();

        $activeId = Uuid::generate();
        $invitedId = Uuid::generate();
        // UUIDv7 shares a timestamp prefix between close-in-time mints, so the whole id (dashes stripped) is
        // the only collision-free local part — and the rows of a concurrent run must not be reachable here.
        $this->activeEmail = \sprintf('opacity-active-%s@erpify.test', \str_replace('-', '', $activeId));
        $this->invitedEmail = \sprintf('opacity-invited-%s@erpify.test', \str_replace('-', '', $invitedId));

        $hash = $this->service(PasswordHasher::class)->hash(self::REGISTERED_PASSWORD);
        $users = $this->service(UserRepository::class);
        $users->save(User::register($activeId, $this->activeEmail, HashedPassword::fromHash($hash), Role::VIEWER));
        $users->save(User::invite($invitedId, $this->invitedEmail, Role::VIEWER));

        $this->seededUserIds = [$activeId, $invitedId];
    }

    protected function tearDown(): void
    {
        // Scoped to the rows this test minted rather than a truncate: the suite runs against a shared
        // database, so removing anything else would be destroying another test's fixture.
        if ([] !== $this->seededUserIds) {
            $this->connection->executeStatement(
                'DELETE FROM identity_user WHERE id IN (?, ?)',
                $this->seededUserIds,
            );
        }

        parent::tearDown();
    }

    public function testTheThreePreIdentityFailuresAnswerOneIndistinguishableResponse(): void
    {
        $answers = [];
        $instances = [];

        foreach ($this->preIdentityFailures() as $case => [$email, $password]) {
            $this->post($email, $password);

            $response = $this->client->getResponse();
            $raw = (string) $response->getContent();

            $this->assertSame(Response::HTTP_UNAUTHORIZED, $response->getStatusCode(), $case . ': ' . $raw);

            $body = $this->decode($raw, $case);
            $instances[] = $this->instanceOf($body, $case, $raw);

            $body['instance'] = self::INSTANCE_PLACEHOLDER;
            unset($body[self::CAUSE_NAMING_MEMBER]);

            // Compared as a whole array rather than member by member: an identical comparison over arrays
            // also holds the key ORDER, so a member added, dropped or moved on one of the three answers is
            // a failure here without anyone having remembered to name it.
            $answers[$case] = [
                'content-type' => $response->headers->get('Content-Type'),
                'body' => $body,
            ];
        }

        // Every case answered, asserted before the comparison: an empty or short map would otherwise
        // make the identity assertions below compare a value against itself, or against nothing.
        $this->assertArrayHasKey(self::WRONG_PASSWORD_CASE, $answers);
        $this->assertArrayHasKey(self::UNKNOWN_EMAIL_CASE, $answers);
        $this->assertArrayHasKey(self::INVITED_CASE, $answers);

        $reference = $answers[self::WRONG_PASSWORD_CASE];

        $this->assertStringContainsString('application/problem+json', (string) $reference['content-type']);
        $this->assertSame($reference, $answers[self::UNKNOWN_EMAIL_CASE]);
        $this->assertSame($reference, $answers[self::INVITED_CASE]);

        // Setting `instance` aside would also hide three answers sharing ONE value, which would be a
        // correlation leak in its own right — so the substitution is only sound while the three are distinct.
        $this->assertCount(3, \array_unique($instances));
    }

    /**
     * @return iterable<string, array{0: string, 1: string}> the submitted email and password, per case
     */
    private function preIdentityFailures(): iterable
    {
        yield self::WRONG_PASSWORD_CASE => [$this->activeEmail, self::WRONG_PASSWORD];
        yield self::UNKNOWN_EMAIL_CASE => ['nobody-' . Uuid::generate() . '@erpify.test', self::WRONG_PASSWORD];
        yield self::INVITED_CASE => [$this->invitedEmail, self::REGISTERED_PASSWORD];
    }

    private function post(string $email, string $password): void
    {
        $this->client->getCookieJar()->clear();
        $this->client->request(
            Request::METHOD_POST,
            self::LOGIN_PATH,
            server: [
                'CONTENT_TYPE' => 'application/json',
                'HTTP_ACCEPT' => 'application/json',
                'HTTP_ORIGIN' => self::ORIGIN,
                'HTTP_X_CORRELATION_ID' => self::CORRELATION_ID,
            ],
            content: (string) \json_encode(['email' => $email, 'password' => $password]),
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function decode(string $raw, string $case): array
    {
        $body = \json_decode($raw, true, flags: JSON_THROW_ON_ERROR);

        $this->assertIsArray($body, $case . ': ' . $raw);

        /** @var array<string, mixed> $body */
        return $body;
    }

    /**
     * @param array<string, mixed> $body
     */
    private function instanceOf(array $body, string $case, string $raw): string
    {
        $instance = $body['instance'] ?? null;

        $this->assertIsString($instance, $case . ': ' . $raw);

        return $instance;
    }

    /**
     * @template T of object
     *
     * @param class-string<T> $id
     *
     * @return T
     */
    private function service(string $id): object
    {
        $service = self::getContainer()->get($id);
        $this->assertInstanceOf($id, $service);

        return $service;
    }
}
