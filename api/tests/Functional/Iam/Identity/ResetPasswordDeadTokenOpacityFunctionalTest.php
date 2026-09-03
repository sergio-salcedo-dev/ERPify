<?php

declare(strict_types=1);

namespace Erpify\Tests\Functional\Iam\Identity;

use DateInterval;
use DateTimeImmutable;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Erpify\Iam\Identity\Application\CompletePasswordReset;
use Erpify\Iam\Identity\Domain\Entity\PasswordResetToken;
use Erpify\Iam\Identity\Domain\Entity\User;
use Erpify\Iam\Identity\Domain\HashedPassword;
use Erpify\Iam\Identity\Domain\Repository\PasswordResetTokenRepository;
use Erpify\Iam\Identity\Domain\Repository\UserRepository;
use Erpify\Iam\Identity\Infrastructure\Http\CompletePasswordResetController;
use Erpify\Shared\Access\Domain\Role;
use Erpify\Shared\Token\Domain\SingleUseToken;
use Erpify\Shared\Uuid\Domain\Uuid;
use Erpify\Tests\Functional\ComparesOpaqueRefusals;
use Erpify\Tests\Functional\ResolvesContainerServices;
use Override;
use PHPUnit\Framework\Attributes\CoversClass;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * The four dead reset links — expired, unknown selector, no separator, and a selector that is not a UUID —
 * must be ONE answer on the wire, since a caller able to tell them apart could probe which selectors exist.
 *
 * The acceptance scenario walks the same four but asserts only the status and the `type` of each, so a member
 * outside those two could diverge with it still green. This compares the whole BODY plus `Content-Type` as
 * one value instead — narrower than "whole responses", since every other header stays outside the
 * comparison and a path that came to touch the session would add three of them.
 *
 * Two members are set aside, and only two. `instance` is minted per error occurrence, so it MUST differ (that
 * it differs is asserted). The `debug` extension exists to name the cause and is emitted under `dev`/`test`
 * only — `prod` omits it entirely — so comparing it would assert the opposite of its own contract; here it
 * would also be the loudest divergence of all, since the four refusals are raised from four different lines.
 *
 * @internal
 *
 * @SuppressWarnings("PHPMD.CouplingBetweenObjects")
 */
#[CoversClass(CompletePasswordResetController::class)]
#[CoversClass(CompletePasswordReset::class)]
final class ResetPasswordDeadTokenOpacityFunctionalTest extends WebTestCase
{
    use ComparesOpaqueRefusals;
    use ResolvesContainerServices;

    private const string RESET_PATH = '/api/v1/backoffice/reset-password';

    private const string ORIGIN = 'http://localhost';

    private const string CSRF_TOKEN = 'a-32-char-stateless-csrf-nonce!!';

    private const string SUBMITTED_PASSWORD = 'a-brand-new-strong-password';

    private const string EXPIRED_CASE = 'a token whose window has lapsed';

    private const string UNKNOWN_CASE = 'a selector no row carries';

    private const string NO_SEPARATOR_CASE = 'a token with no selector-verifier separator';

    private const string NON_UUID_CASE = 'a selector that is not a UUID';

    private KernelBrowser $client;

    private Connection $connection;

    /** @var list<string> ids of the identities this test seeded, so teardown removes those rows and no others */
    private array $seededUserIds = [];

    /** @var list<string> ids of the reset tokens this test seeded */
    private array $seededTokenIds = [];

    private string $expiredToken = '';

    #[Override]
    protected function setUp(): void
    {
        $this->client = self::createClient();
        // Persistent kernel so the cached connection and container stay valid across the four requests one
        // test makes; the cookie jar is cleared per request so each attempt is a clean anonymous call.
        $this->client->disableReboot();

        $this->connection = $this->service(EntityManagerInterface::class)->getConnection();

        $userId = Uuid::generate();
        // UUIDv7 shares a timestamp prefix between close-in-time mints, so the whole id (dashes stripped) is
        // the only collision-free local part — and the rows of a concurrent run must not be reachable here.
        $email = \sprintf('reset-opacity-%s@erpify.test', \str_replace('-', '', $userId));

        $this->service(UserRepository::class)->save(
            User::register($userId, $email, HashedPassword::fromHash('an-opaque-precomputed-hash'), Role::VIEWER),
        );
        $this->seededUserIds = [$userId];

        $tokenId = Uuid::generate();
        $lapsed = SingleUseToken::mint((new DateTimeImmutable())->sub(new DateInterval('P1D')));
        $this->service(PasswordResetTokenRepository::class)->save(
            PasswordResetToken::issue($tokenId, $userId, $lapsed->token),
        );
        $this->seededTokenIds = [$tokenId];
        // The secret is the right one: what kills this link is the lapsed window and nothing else, which is
        // the case a wrong-secret token could not stand in for.
        $this->expiredToken = $tokenId . '.' . $lapsed->plaintext();
    }

    protected function tearDown(): void
    {
        // Scoped to the rows this test minted rather than a truncate: the suite runs against a shared
        // database, so removing anything else would be destroying another test's fixture.
        foreach ($this->seededTokenIds as $seededTokenId) {
            $this->connection->executeStatement(
                'DELETE FROM identity_password_reset_token WHERE id = ?',
                [$seededTokenId],
            );
        }

        foreach ($this->seededUserIds as $seededUserId) {
            $this->connection->executeStatement('DELETE FROM identity_user WHERE id = ?', [$seededUserId]);
        }

        parent::tearDown();
    }

    public function testTheFourDeadLinksAnswerOneIndistinguishableResponse(): void
    {
        $answers = [];
        $perOccurrence = [];

        foreach ($this->deadTokens() as $case => $deadToken) {
            $this->post($deadToken);

            $response = $this->client->getResponse();
            $raw = (string) $response->getContent();

            $this->assertSame(Response::HTTP_BAD_REQUEST, $response->getStatusCode(), $case . ': ' . $raw);

            [$body, $members] = $this->comparableRefusal($raw, $case);
            $perOccurrence[] = $members;

            $answers[$case] = [
                'content-type' => $response->headers->get('Content-Type'),
                'body' => $body,
            ];
        }

        // Every case answered, asserted before the comparison: an empty or short map would otherwise make
        // the identity assertions below compare a value against itself, or against nothing.
        $this->assertArrayHasKey(self::EXPIRED_CASE, $answers);
        $this->assertArrayHasKey(self::UNKNOWN_CASE, $answers);
        $this->assertArrayHasKey(self::NO_SEPARATOR_CASE, $answers);
        $this->assertArrayHasKey(self::NON_UUID_CASE, $answers);

        $reference = $answers[self::EXPIRED_CASE];

        $this->assertArrayHasKey('type', $reference['body']);
        $this->assertSame('invalid-token', $reference['body']['type']);
        $this->assertStringContainsString('application/problem+json', (string) $reference['content-type']);

        $this->assertRefusalsAreIndistinguishable($answers, $perOccurrence);
    }

    /**
     * @return iterable<string, string> the token submitted, per case
     */
    private function deadTokens(): iterable
    {
        yield self::EXPIRED_CASE => $this->expiredToken;
        yield self::UNKNOWN_CASE => Uuid::generate() . '.a-secret-no-row-carries';
        yield self::NO_SEPARATOR_CASE => 'not-a-selector-verifier-shape';
        yield self::NON_UUID_CASE => 'not-a-uuid-selector.some-secret';
    }

    private function post(string $token): void
    {
        $this->client->getCookieJar()->clear();
        $this->client->request(
            Request::METHOD_POST,
            self::RESET_PATH,
            server: [
                'CONTENT_TYPE' => 'application/json',
                'HTTP_ACCEPT' => 'application/json',
                'HTTP_ORIGIN' => self::ORIGIN,
                'HTTP_X_CSRF_TOKEN' => self::CSRF_TOKEN,
            ],
            content: (string) \json_encode([
                'token' => $token,
                'password' => self::SUBMITTED_PASSWORD,
            ]),
        );
    }
}
