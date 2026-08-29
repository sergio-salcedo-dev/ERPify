<?php

declare(strict_types=1);

namespace Erpify\Tests\Functional\Iam\Invitation;

use DateInterval;
use DateTimeImmutable;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Erpify\Iam\Identity\Domain\Entity\User;
use Erpify\Iam\Identity\Domain\Enum\IdentityStatus;
use Erpify\Iam\Identity\Domain\Repository\UserRepository;
use Erpify\Iam\Invitation\Domain\Entity\Invitation;
use Erpify\Iam\Invitation\Domain\Enum\InvitationStatus;
use Erpify\Iam\Invitation\Domain\Repository\InvitationRepository;
use Erpify\Iam\Session\Domain\Repository\SessionRepository;
use Erpify\Organization\Membership\Domain\Entity\Membership;
use Erpify\Organization\Membership\Domain\Repository\MembershipRepository;
use Erpify\Organization\Organization\Domain\Entity\Organization;
use Erpify\Organization\Organization\Domain\Repository\OrganizationRepository;
use Erpify\Shared\Access\Domain\Role;
use Erpify\Shared\Token\Domain\SingleUseToken;
use Erpify\Shared\Uuid\Domain\Uuid;
use Erpify\Tests\Functional\ComparesOpaqueRefusals;
use Erpify\Tests\Functional\ResolvesContainerServices;
use Override;
use PHPUnit\Framework\Attributes\CoversClass;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Response;

/**
 * Drives the public accept endpoint end-to-end against REAL Postgres and the real firewall. Proves the happy
 * path activates the identity, retires the invitation and mints exactly one session (Security::login fires the
 * login path once), that all six dead-token cases collapse to one uniform 400 invalid-token, and that a
 * cross-site POST is rejected 403 without mutation.
 *
 * A full-flow functional test against the real graph legitimately touches many types (three contexts'
 * aggregates + repositories, the firewall, the token) — the coupling is inherent, not a design smell.
 *
 * @internal
 *
 * @SuppressWarnings("PHPMD.CouplingBetweenObjects")
 */
#[CoversClass(\Erpify\Iam\Invitation\Infrastructure\Http\AcceptInvitationController::class)]
#[CoversClass(\Erpify\Iam\Invitation\Infrastructure\Http\AcceptInvitationOriginListener::class)]
#[CoversClass(\Erpify\Iam\Invitation\Infrastructure\Persistence\Doctrine\DoctrineInvitationRepository::class)]
final class InvitationAcceptFunctionalTest extends WebTestCase
{
    use ComparesOpaqueRefusals;
    use ResolvesContainerServices;

    private const string ACCEPT_PATH = '/api/v1/backoffice/invitations/accept';

    private const string ORIGIN = 'http://localhost';

    private const string PASSWORD = 'a-valid-password';

    private const string CSRF_TOKEN = 'a-32-char-stateless-csrf-nonce!!';

    /**
     * A canonical lowercase UUIDv7, the only shape the correlation listener echoes back. Without it the
     * listener mints one per request, so the six refusals would carry six different `correlation-id` values
     * and the whole-body comparison below would fail on a member that says nothing about the cause.
     */
    private const string CORRELATION_ID = '0190a1de-0602-7abc-8def-000000000071';

    private const string TRUNCATE_SQL
        = 'TRUNCATE iam_invitation, iam_session, membership, organization, identity_user, event_store CASCADE';

    private KernelBrowser $client;

    private Connection $connection;

    private string $organizationId;

    #[Override]
    protected function setUp(): void
    {
        $this->client = self::createClient();
        // Persistent kernel so the cached connection + container stay valid across the several requests a test
        // makes; cookies are cleared per request in post() so each accept is a clean anonymous call.
        $this->client->disableReboot();

        $entityManager = $this->service(EntityManagerInterface::class);
        $this->connection = $entityManager->getConnection();
        $this->truncate();

        $this->organizationId = Uuid::generate();
        $this->service(OrganizationRepository::class)->save(Organization::provision($this->organizationId, 'ACME'));
    }

    protected function tearDown(): void
    {
        $this->truncate();
        parent::tearDown();
    }

    public function testAcceptingActivatesTheIdentityRetiresTheInvitationAndMintsExactlyOneSession(): void
    {
        [$userId, $token] = $this->seedSentInvitation();

        $this->post($token, self::ORIGIN);

        $this->assertStatus(Response::HTTP_NO_CONTENT);

        $this->service(EntityManagerInterface::class)->clear();
        $user = $this->service(UserRepository::class)->findById($userId);
        $this->assertInstanceOf(User::class, $user);
        $this->assertSame(IdentityStatus::ACTIVE, $user->status());

        $invitation = $this->service(InvitationRepository::class)->findById($this->onlyInvitationId());
        $this->assertInstanceOf(Invitation::class, $invitation);
        $this->assertSame(InvitationStatus::ACCEPTED, $invitation->status());

        // The login path minted the first session — exactly one, no silent double-mint.
        $this->assertCount(1, $this->service(SessionRepository::class)->findByUserId($userId));
    }

    public function testAllSixDeadTokenCasesReturnOneUniformInvalidToken(): void
    {
        $answers = [];
        $instances = [];

        $deadTokens = $this->deadTokens();

        // Distinct tokens, asserted rather than counted: two cases collapsing onto the same token would leave
        // six answers agreeing trivially while five causes are exercised, and nothing would go red — neither
        // the count nor the instances, which are minted per occurrence rather than per cause.
        $this->assertCount(6, $deadTokens);
        $this->assertCount(6, \array_unique($deadTokens));

        foreach ($deadTokens as $case => $deadToken) {
            $this->post($deadToken, self::ORIGIN);

            $response = $this->client->getResponse();
            $raw = (string) $response->getContent();

            $this->assertSame(Response::HTTP_BAD_REQUEST, $response->getStatusCode(), $case . ': ' . $raw);

            [$body, $instance] = $this->comparableRefusal($raw, $case);
            $instances[] = $instance;

            // The six are raised from four different lines of the use case, so anything that carried that
            // provenance onto the wire — an extension, a differing `detail`, a reordered payload — diverges.
            $answers[$case] = [
                'content-type' => $response->headers->get('Content-Type'),
                'body' => $body,
            ];
        }

        // Every cause answered, asserted before the comparison: a short map would let the identity assertions
        // below compare a value against itself.
        $this->assertSame(\array_keys($deadTokens), \array_keys($answers));

        $this->assertArrayHasKey('no separator', $answers);

        $reference = $answers['no separator'];

        $this->assertArrayHasKey('type', $reference['body']);
        $this->assertSame('invalid-token', $reference['body']['type']);
        $this->assertStringContainsString('application/problem+json', (string) $reference['content-type']);

        $this->assertRefusalsAreIndistinguishable($answers, $instances);
    }

    public function testACrossSitePostIsRejectedWithoutMutating(): void
    {
        [$userId, $token] = $this->seedSentInvitation();

        $this->post($token, 'https://evil.example');

        $this->assertStatus(Response::HTTP_FORBIDDEN);

        $this->service(EntityManagerInterface::class)->clear();
        $user = $this->service(UserRepository::class)->findById($userId);
        $this->assertInstanceOf(User::class, $user);
        $this->assertSame(IdentityStatus::INVITED, $user->status());
        $this->assertSame([], $this->service(SessionRepository::class)->findByUserId($userId));
    }

    private function post(string $token, string $origin): void
    {
        $this->client->getCookieJar()->clear();
        $this->client->request(
            \Symfony\Component\HttpFoundation\Request::METHOD_POST,
            self::ACCEPT_PATH,
            server: [
                'CONTENT_TYPE' => 'application/json',
                'HTTP_ORIGIN' => $origin,
                'HTTP_X_CSRF_TOKEN' => self::CSRF_TOKEN,
                'HTTP_X_CORRELATION_ID' => self::CORRELATION_ID,
            ],
            content: (string) \json_encode([
                'token' => $token,
                'password' => self::PASSWORD,
            ]),
        );
    }

    private function assertStatus(int $expected): void
    {
        $response = $this->client->getResponse();
        $this->assertSame($expected, $response->getStatusCode(), (string) $response->getContent());
    }

    /**
     * @return array{0: string, 1: string} the invited user id and the raw `<invitationId>.<secret>` token
     */
    private function seedSentInvitation(?DateTimeImmutable $expiresAt = null): array
    {
        $userId = Uuid::generate();
        // UUIDv7 shares a timestamp prefix between close-in-time mints, so the whole id (dashes stripped) is the
        // only collision-free local part when a single test seeds several invitees.
        $email = \sprintf('invitee-%s@erpify.test', \str_replace('-', '', $userId));

        $this->service(UserRepository::class)->save(User::invite($userId, $email, Role::VIEWER));
        $this->service(MembershipRepository::class)->save(
            Membership::grant(Uuid::generate(), $userId, $this->organizationId),
        );

        $generated = SingleUseToken::mint($expiresAt ?? (new DateTimeImmutable())->add(new DateInterval('P3D')));
        $invitationId = Uuid::generate();
        $invitation = Invitation::create($invitationId, $this->organizationId, $userId, $generated->token);
        $invitation->markSent();
        $invitation->pullDomainEvents();
        $this->service(InvitationRepository::class)->save($invitation);

        return [$userId, $invitationId . '.' . $generated->plaintext()];
    }

    /**
     * @return array<string, string> the six dead-token shapes keyed by cause: malformed, non-existent, wrong
     *                               secret, expired, accepted and revoked
     */
    private function deadTokens(): array
    {
        [, $validToken] = $this->seedSentInvitation();
        $validInvitationId = \substr($validToken, 0, (int) \strpos($validToken, '.'));

        [, $expiredToken] = $this->seedSentInvitation((new DateTimeImmutable())->sub(new DateInterval('P1D')));

        [$acceptedUserId, $acceptedToken] = $this->seedSentInvitation();
        $this->post($acceptedToken, self::ORIGIN);
        $this->assertStatus(Response::HTTP_NO_CONTENT);
        $this->service(EntityManagerInterface::class)->clear();
        unset($acceptedUserId);

        // Keyed by cause so a failure names which refusal diverged, and so two cases cannot silently collapse
        // into the same token: a positional list of six stays six however many distinct causes it exercises.
        return [
            'no separator' => 'malformed-no-separator',
            'unknown selector' => Uuid::generate() . '.a-non-existent-secret',
            'wrong secret' => $validInvitationId . '.the-wrong-secret',
            'expired' => $expiredToken,
            'already accepted' => $acceptedToken,
            'revoked' => $this->revokedToken(),
        ];
    }

    /**
     * A withdrawn invitation reaches the accept flow with a token whose secret still verifies, so the only
     * thing refusing it is the `SENT` guard — the same guard the already-accepted case meets. Presenting it
     * pins the enumeration the endpoint claims rather than the branch, which is already reached.
     *
     * @return string the raw `<invitationId>.<secret>` token of an invitation revoked after it was sent
     */
    private function revokedToken(): string
    {
        [, $token] = $this->seedSentInvitation();
        $invitationId = \substr($token, 0, (int) \strpos($token, '.'));

        $invitations = $this->service(InvitationRepository::class);
        $invitation = $invitations->findById($invitationId);
        $this->assertInstanceOf(Invitation::class, $invitation);

        $invitation->revoke();
        $invitation->pullDomainEvents();

        $invitations->save($invitation);

        return $token;
    }

    private function onlyInvitationId(): string
    {
        $id = $this->connection->fetchOne('SELECT id FROM iam_invitation LIMIT 1');
        $this->assertIsString($id);

        return $id;
    }

    private function truncate(): void
    {
        $this->connection->executeStatement(self::TRUNCATE_SQL);
    }
}
