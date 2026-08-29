<?php

declare(strict_types=1);

namespace Erpify\Tests\Functional\Iam\Invitation;

use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Erpify\Iam\Identity\Domain\Email;
use Erpify\Iam\Identity\Domain\Entity\User;
use Erpify\Iam\Identity\Domain\Enum\IdentityStatus;
use Erpify\Iam\Invitation\Domain\Entity\Invitation;
use Erpify\Iam\Invitation\Domain\Enum\InvitationStatus;
use Erpify\Iam\Invitation\Domain\Repository\InvitationRepository;
use Erpify\Iam\Invitation\Infrastructure\Cli\CreateInvitationCommand;
use Erpify\Iam\Invitation\Infrastructure\Cli\ResendInvitationCommand;
use Erpify\Iam\Invitation\Infrastructure\Cli\RevokeInvitationCommand;
use Erpify\Organization\Membership\Domain\Entity\Membership;
use Erpify\Organization\Membership\Domain\Repository\MembershipRepository;
use Erpify\Organization\Organization\Domain\Entity\Organization;
use Erpify\Organization\Organization\Domain\Repository\OrganizationRepository;
use Erpify\Shared\Uuid\Domain\Uuid;
use Erpify\Tests\Functional\ResolvesContainerServices;
use Override;
use PHPUnit\Framework\Attributes\CoversClass;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * Drives the invitation CLI end-to-end against REAL Postgres: create funnels an INVITED identity through a
 * membership and emits a SENT invitation; revoke and resend transition it. The commit path matters (create
 * wraps three aggregates in one transaction), so each test truncates the graph. The accept token reaches
 * stdout only under `--show-token`, which these tests pass wherever they need to read it.
 *
 * A full-flow CLI functional test against the real graph legitimately touches many types; the coupling is
 * inherent to driving the three-context invite/revoke/resend flow, not a design smell.
 *
 * @internal
 *
 * @SuppressWarnings("PHPMD.CouplingBetweenObjects")
 */
#[CoversClass(CreateInvitationCommand::class)]
#[CoversClass(RevokeInvitationCommand::class)]
#[CoversClass(ResendInvitationCommand::class)]
#[CoversClass(\Erpify\Iam\Invitation\Infrastructure\Mail\SymfonyInvitationEmailSender::class)]
final class InvitationCliFunctionalTest extends KernelTestCase
{
    use ResolvesContainerServices;

    private const string TRUNCATE_SQL
        = 'TRUNCATE iam_invitation, iam_session, membership, organization, identity_user, event_store CASCADE';

    private Connection $connection;

    private EntityManagerInterface $entityManager;

    private Application $application;

    #[Override]
    protected function setUp(): void
    {
        $kernel = self::bootKernel();

        $this->entityManager = $this->service(EntityManagerInterface::class);
        $this->connection = $this->entityManager->getConnection();
        $this->application = new Application($kernel);

        $this->truncate();
        $this->service(OrganizationRepository::class)->save(Organization::provision(Uuid::generate(), 'ACME'));
    }

    protected function tearDown(): void
    {
        $this->truncate();
        parent::tearDown();
    }

    public function testCreateInvitesFunnelsThroughMembershipEmitsASentInvitationAndPrintsTheToken(): void
    {
        $tester = $this->create('invitee@erpify.test', 'EDITOR');

        $this->assertSame(Command::SUCCESS, $tester->getStatusCode());
        $token = $this->printedToken($tester);
        $this->assertStringContainsString('.', $token);

        $this->entityManager->clear();

        $user = $this->service(\Erpify\Iam\Identity\Domain\Repository\UserRepository::class)
            ->findByEmail(Email::from('invitee@erpify.test'))
        ;
        $this->assertInstanceOf(User::class, $user);
        $this->assertSame(IdentityStatus::INVITED, $user->status());
        $userId = $user->getId();
        $this->assertNotNull($userId);

        $membership = $this->service(MembershipRepository::class)->findByUserId($userId);
        $this->assertInstanceOf(Membership::class, $membership);

        $invitation = $this->service(InvitationRepository::class)->findById($this->tokenSelector($token));
        $this->assertInstanceOf(Invitation::class, $invitation);
        $this->assertSame(InvitationStatus::SENT, $invitation->status());
        $this->assertSame($userId, $invitation->invitedUserId());
    }

    public function testRevokeTransitionsTheInvitationToRevoked(): void
    {
        $token = $this->printedToken($this->create('to-revoke@erpify.test'));
        $invitationId = $this->tokenSelector($token);

        $tester = new CommandTester($this->application->find('iam:invitation:revoke'));
        $tester->execute(['invitationId' => $invitationId]);

        $this->assertSame(Command::SUCCESS, $tester->getStatusCode());
        $this->entityManager->clear();
        $invitation = $this->service(InvitationRepository::class)->findById($invitationId);
        $this->assertInstanceOf(Invitation::class, $invitation);
        $this->assertSame(InvitationStatus::REVOKED, $invitation->status());
    }

    public function testResendReissuesAFreshToken(): void
    {
        $firstToken = $this->printedToken($this->create('to-resend@erpify.test'));
        $invitationId = $this->tokenSelector($firstToken);

        $tester = new CommandTester($this->application->find('iam:invitation:resend'));
        $tester->execute(['invitationId' => $invitationId, '--show-token' => true]);

        $this->assertSame(Command::SUCCESS, $tester->getStatusCode());
        $secondToken = $this->printedToken($tester);
        $this->assertStringStartsWith($invitationId . '.', $secondToken);
        $this->assertNotSame($firstToken, $secondToken);
    }

    public function testCreateRejectsAnUnknownRole(): void
    {
        $tester = $this->create('bad-role@erpify.test', 'NOT_A_ROLE');

        $this->assertSame(Command::FAILURE, $tester->getStatusCode());
        $this->entityManager->clear();
        $this->assertNotInstanceOf(
            User::class,
            $this->service(\Erpify\Iam\Identity\Domain\Repository\UserRepository::class)
                ->findByEmail(Email::from('bad-role@erpify.test')),
        );
    }

    public function testRevokeRejectsAMissingInvitation(): void
    {
        $tester = new CommandTester($this->application->find('iam:invitation:revoke'));
        $tester->execute(['invitationId' => Uuid::generate()]);

        $this->assertSame(Command::FAILURE, $tester->getStatusCode());
    }

    /**
     * `--show-token` is explicit here because the command's default is silence: the raw token is a credential
     * and stdout is not a private channel. A test that needs the token asks for it, exactly as an operator does.
     */
    private function create(string $email, string ...$roles): CommandTester
    {
        $tester = new CommandTester($this->application->find('iam:invitation:create'));
        $tester->execute(['email' => $email, 'roles' => $roles, '--show-token' => true]);

        return $tester;
    }

    private function printedToken(CommandTester $tester): string
    {
        $lines = \array_values(\array_filter(
            \array_map(trim(...), \explode("\n", $tester->getDisplay())),
            static fn (string $line): bool => \str_contains($line, '.') && !\str_contains($line, ' '),
        ));
        $this->assertNotEmpty($lines, 'the command must print the accept token');

        return $lines[0];
    }

    private function tokenSelector(string $token): string
    {
        return \substr($token, 0, (int) \strpos($token, '.'));
    }

    private function truncate(): void
    {
        $this->connection->executeStatement(self::TRUNCATE_SQL);
    }
}
