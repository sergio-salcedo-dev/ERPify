<?php

declare(strict_types=1);

namespace Erpify\Tests\Functional\Iam\Identity\Infrastructure\Controller;

use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use Erpify\Iam\Identity\Infrastructure\Controller\UserEraseController;
use Erpify\Iam\Session\Domain\Entity\Session;
use Erpify\Iam\Session\Domain\Repository\SessionRepository;
use Erpify\Iam\Session\Domain\SessionId;
use Erpify\Shared\Access\Domain\Role;
use Erpify\Shared\Audit\Application\AuditActorAnonymiser;
use Erpify\Tests\DataFixtures\UserFixtureFactory;
use Erpify\Tests\Functional\AuthenticatesFunctionalRequests;
use Erpify\Tests\Functional\Iam\Identity\Fixtures\FailingAuditActorAnonymiser;
use Override;
use PHPUnit\Framework\Attributes\CoversClass;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Request;

/**
 * End-to-end wire-gate for `DELETE /api/v1/backoffice/users/{id}` on the real, wired container. It proves the
 * endpoint autowires the whole chained erasure — a live subject is hard-deleted, its historical audit rows are
 * anonymised (`actor_erased = TRUE`), its sessions are dropped and the compliance rows land signed by the
 * acting ADMIN — and that the surface answers 204 / 403 / 400 / 404 / 409 as the contract requires.
 *
 * On administrators: erasure refuses any subject still carrying `ADMIN` with 409
 * `administrator-erasure-requires-demotion`, so over HTTP an administrator targeting a peer is stopped before
 * the irreversible anonymisation and must record the demotion first. Targeting themselves is refused earlier
 * still, with 409 `self-erasure-forbidden`. The ≥1-admin invariant is no longer reachable from this path — the
 * last active administrator necessarily carries the role — and now binds only on the role and status
 * transitions; their unit and Behat coverage prove it.
 *
 * @internal
 *
 * @SuppressWarnings("PHPMD.CouplingBetweenObjects")
 */
#[CoversClass(UserEraseController::class)]
final class UserEraseFunctionalTest extends WebTestCase
{
    use AuthenticatesFunctionalRequests;

    private const string ENDPOINT = '/api/v1/backoffice/users';

    private const string TARGET_ID = '0190f200-0000-7000-8000-0000000000e1';

    private const string TARGET_EMAIL = 'erase-target@erpify.test';

    private const string SEEDED_AUDIT_ID = '0190f200-0000-7000-8000-0000000000e2';

    private const string ORGANIZATION_ID = '0190a1b2-c3d4-7e5f-8a9b-0c1d2e3f4a50';

    private KernelBrowser $client;

    #[Override]
    protected function setUp(): void
    {
        $this->client = self::createClient();
    }

    public function testAnAdminErasesASubjectIdentityTrailAndSessionsAndGetsNoContent(): void
    {
        $this->resetSubject();
        $this->persistSubject();
        $this->seedSubjectAuditRow();
        $this->seedSubjectSession();
        $this->authenticateAdminClient($this->client);

        $this->erase(self::TARGET_ID, expectedStatusCode: 204);

        // Identity hard-deleted, sessions dropped, the subject's historical audit row anonymised.
        $this->assertSame(0, $this->countIdentity(self::TARGET_ID));
        $this->assertSame(0, $this->countActiveSessionRows(self::TARGET_ID));
        $this->assertSame(0, $this->countAuditRowsAttributedTo(self::TARGET_ID));
        $this->assertTrue($this->seededAuditRowIsErased());
        // The compliance rows survive, attributed to the acting ADMIN (never the subject).
        $adminId = $this->idOfEmail(self::FUNCTIONAL_ADMIN_EMAIL);
        $this->assertSame(1, $this->countActionByActor('GDPR_SUBJECT_ERASED', $adminId));
        $this->assertSame(1, $this->countActionByActor('GDPR_ERASURE_EXECUTED', $adminId));
    }

    public function testAFailedTrailAnonymisationRollsBackTheEntireErasure(): void
    {
        $this->resetSubject();
        $this->persistSubject();
        $this->seedSubjectAuditRow();
        $this->seedSubjectSession();

        // Fail the anonymisation link — outside the request's auth path — after the identity delete but before the
        // session purge. disableReboot keeps the override in the container the erase request resolves from.
        self::getContainer()->set(AuditActorAnonymiser::class, new FailingAuditActorAnonymiser());
        $this->client->disableReboot();
        $this->authenticateAdminClient($this->client);

        // Compliance rows are counted as a delta — sibling tests on the shared dev DB leave admin-signed rows.
        $adminId = $this->idOfEmail(self::FUNCTIONAL_ADMIN_EMAIL);
        $subjectErasedBefore = $this->countActionByActor('GDPR_SUBJECT_ERASED', $adminId);
        $erasureExecutedBefore = $this->countActionByActor('GDPR_ERASURE_EXECUTED', $adminId);

        $this->erase(self::TARGET_ID, expectedStatusCode: 500);

        // The whole unit rolled back: the identity, its still-attributed (un-erased) audit row and its session all
        // survive, and neither compliance row was written for this erase.
        $this->assertSame(1, $this->countIdentity(self::TARGET_ID));
        $this->assertSame(1, $this->countActiveSessionRows(self::TARGET_ID));
        $this->assertSame(1, $this->countAuditRowsAttributedTo(self::TARGET_ID));
        $this->assertFalse($this->seededAuditRowIsErased());
        $this->assertSame($subjectErasedBefore, $this->countActionByActor('GDPR_SUBJECT_ERASED', $adminId));
        $this->assertSame($erasureExecutedBefore, $this->countActionByActor('GDPR_ERASURE_EXECUTED', $adminId));
    }

    public function testANonAdminIsForbidden(): void
    {
        $this->resetSubject();
        $this->persistSubject();
        // functional (MANAGER + AUDIT_READER) holds no users.erase — users opts out of tier auto-grant.
        $this->authenticateClient($this->client);

        $this->erase(self::TARGET_ID, expectedStatusCode: 403);

        $this->assertSame(1, $this->countIdentity(self::TARGET_ID));
    }

    public function testAnAdminCannotEraseTheirOwnIdentity(): void
    {
        $this->authenticateAdminClient($this->client);
        $adminId = $this->idOfEmail(self::FUNCTIONAL_ADMIN_EMAIL);

        $this->erase($adminId, expectedStatusCode: 409);

        self::assertResponseHeaderSame('Content-Type', 'application/problem+json');
        // The acting admin still exists — the refusal precedes any mutation.
        $this->assertSame(1, $this->countIdentity($adminId));
    }

    public function testAMalformedIdIsRejectedWithFourHundred(): void
    {
        $this->authenticateAdminClient($this->client);

        $this->erase('not-a-uuid', expectedStatusCode: 400);
    }

    public function testAWellFormedButUnknownIdIsAFourOhFour(): void
    {
        $this->authenticateAdminClient($this->client);

        $this->erase('2e6d865c-17b0-476a-85f2-037bf6d3b3dc', expectedStatusCode: 404);
    }

    private function erase(string $id, int $expectedStatusCode): void
    {
        $this->client->request(
            Request::METHOD_DELETE,
            self::ENDPOINT . '/' . $id,
            server: ['HTTP_ACCEPT' => 'application/json'],
        );

        self::assertResponseStatusCodeSame($expectedStatusCode);
    }

    private function resetSubject(): void
    {
        $connection = $this->functionalConnection();
        $connection->executeStatement('DELETE FROM iam_session WHERE user_id = :id', ['id' => self::TARGET_ID]);
        $connection->executeStatement('DELETE FROM audit_log WHERE id = :id', ['id' => self::SEEDED_AUDIT_ID]);
        $connection->executeStatement(
            'DELETE FROM audit_log WHERE actor_id = CAST(:id AS uuid)',
            ['id' => self::TARGET_ID],
        );
        $connection->executeStatement(
            'DELETE FROM identity_user WHERE email = :email',
            ['email' => self::TARGET_EMAIL],
        );
    }

    private function persistSubject(): void
    {
        $entityManager = $this->entityManager();
        $entityManager->persist(
            UserFixtureFactory::create(self::TARGET_ID, self::TARGET_EMAIL, 'erase-password', [Role::VIEWER->value]),
        );
        $entityManager->flush();
        $entityManager->clear();
    }

    private function seedSubjectAuditRow(): void
    {
        $this->functionalConnection()->executeStatement(
            'INSERT INTO audit_log '
            . '(id, level, action, actor_type, actor_id, correlation_id, resource_type, resource_id, '
            . 'metadata, actor_erased, resource_erased, occurred_on) '
            . 'VALUES (:id, :level, :action, :actorType, CAST(:actorId AS uuid), :correlationId, :resourceType, '
            . ':resourceId, :metadata, FALSE, FALSE, :occurredOn)',
            [
                'id' => self::SEEDED_AUDIT_ID,
                'level' => 'change',
                'action' => 'BANK_UPDATED',
                'actorType' => 'user',
                'actorId' => self::TARGET_ID,
                'correlationId' => '0190f200-0000-7000-8000-0000000000e3',
                'resourceType' => 'Bank',
                'resourceId' => '0190f200-0000-7000-8000-0000000000e4',
                'metadata' => '{}',
                'occurredOn' => '2026-03-01 12:00:00+00',
            ],
        );
    }

    private function seedSubjectSession(): void
    {
        $sessions = self::getContainer()->get(SessionRepository::class);
        $this->assertInstanceOf(SessionRepository::class, $sessions);

        $session = Session::start(
            SessionId::generate()->toString(),
            self::TARGET_ID,
            self::ORGANIZATION_ID,
            'Erase-target device',
            '203.0.113.7',
            new DateTimeImmutable('+1 day'),
        );
        $session->pullDomainEvents();

        $sessions->save($session);
    }

    private function countIdentity(string $id): int
    {
        return $this->countRows(
            'SELECT COUNT(*) FROM identity_user WHERE id = CAST(:id AS uuid)',
            ['id' => $id],
        );
    }

    private function countActiveSessionRows(string $userId): int
    {
        return $this->countRows(
            'SELECT COUNT(*) FROM iam_session WHERE user_id = CAST(:id AS uuid)',
            ['id' => $userId],
        );
    }

    private function countAuditRowsAttributedTo(string $actorId): int
    {
        return $this->countRows(
            'SELECT COUNT(*) FROM audit_log WHERE actor_id = CAST(:id AS uuid)',
            ['id' => $actorId],
        );
    }

    private function countActionByActor(string $action, string $actorId): int
    {
        return $this->countRows(
            'SELECT COUNT(*) FROM audit_log WHERE action = :action AND actor_id = CAST(:id AS uuid)',
            ['action' => $action, 'id' => $actorId],
        );
    }

    private function seededAuditRowIsErased(): bool
    {
        return 1 === $this->countRows(
            'SELECT COUNT(*) FROM audit_log WHERE id = :id AND actor_erased = TRUE',
            ['id' => self::SEEDED_AUDIT_ID],
        );
    }

    private function idOfEmail(string $email): string
    {
        $id = $this->functionalConnection()->fetchOne(
            'SELECT id FROM identity_user WHERE email = :email',
            ['email' => $email],
        );
        $this->assertIsString($id);

        return $id;
    }

    /**
     * @param array<string, mixed> $parameters
     */
    private function countRows(string $sql, array $parameters): int
    {
        $count = $this->functionalConnection()->fetchOne($sql, $parameters);

        return \is_numeric($count) ? (int) $count : 0;
    }

    private function entityManager(): EntityManagerInterface
    {
        $entityManager = self::getContainer()->get(EntityManagerInterface::class);
        $this->assertInstanceOf(EntityManagerInterface::class, $entityManager);

        return $entityManager;
    }
}
