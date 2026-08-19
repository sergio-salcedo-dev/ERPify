<?php

declare(strict_types=1);

namespace Erpify\Tests\Functional\Organization;

use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Erpify\Iam\Identity\Domain\Email;
use Erpify\Iam\Identity\Domain\Entity\User;
use Erpify\Iam\Identity\Domain\Repository\UserRepository;
use Erpify\Iam\Identity\Infrastructure\Cli\CreateInitialAdministratorCommand;
use Erpify\Organization\Membership\Domain\Entity\Membership;
use Erpify\Organization\Membership\Domain\Repository\MembershipRepository;
use Erpify\Organization\Organization\Infrastructure\Cli\ProvisionOrganizationCommand;
use Erpify\Shared\Access\Domain\Role;
use Override;
use PHPUnit\Framework\Attributes\CoversClass;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * Proves the bootstrap sequence end-to-end against REAL Postgres: provisioning the organization then
 * creating the first administrator yields an identity carrying ADMIN, a membership admitting it to that
 * organization, and the "one org per installation", "no user without a membership" and "at least one active
 * ADMIN" invariants. The commit path matters here (the admin command wraps identity + membership in one
 * transaction), so each test truncates the organization graph before and after instead of running inside a
 * rolled-back transaction.
 *
 * @internal
 */
#[CoversClass(ProvisionOrganizationCommand::class)]
#[CoversClass(CreateInitialAdministratorCommand::class)]
final class BootstrapCommandsTest extends KernelTestCase
{
    private const string TRUNCATE_SQL = 'TRUNCATE membership, organization, identity_user';

    private Connection $connection;

    private EntityManagerInterface $entityManager;

    private Application $application;

    private MembershipRepository $memberships;

    private UserRepository $users;

    #[Override]
    protected function setUp(): void
    {
        $kernel = self::bootKernel();

        $entityManager = self::getContainer()->get(EntityManagerInterface::class);
        $this->assertInstanceOf(EntityManagerInterface::class, $entityManager);
        $this->entityManager = $entityManager;
        $this->connection = $entityManager->getConnection();

        $this->memberships = $this->service(MembershipRepository::class);
        $this->users = $this->service(UserRepository::class);

        $this->application = new Application($kernel);
        $this->truncate();
    }

    protected function tearDown(): void
    {
        $this->truncate();
        parent::tearDown();
    }

    public function testBootstrapsTheOrganizationAndFirstAdministrator(): void
    {
        $this->assertSame(Command::SUCCESS, $this->provision('ERPify')->getStatusCode());
        $this->assertSame(Command::SUCCESS, $this->createAdministrator('admin@erpify.test')->getStatusCode());

        $this->entityManager->clear();

        $admin = $this->users->findByEmail(Email::from('admin@erpify.test'));
        $this->assertInstanceOf(User::class, $admin);
        $adminId = $admin->getId();
        $this->assertNotNull($adminId);
        $this->assertSame([Role::ADMIN], $admin->roles());

        // The organization is reachable only through the membership: its existence is guaranteed by the
        // membership.organization_id FK, so a membership carrying an org id proves the org was provisioned.
        $membership = $this->memberships->findByUserId($adminId);
        $this->assertInstanceOf(Membership::class, $membership);

        $this->assertAdminBelongsToTheOrganization($membership->organizationId(), $adminId);
    }

    public function testRejectsASecondOrganization(): void
    {
        $this->assertSame(Command::SUCCESS, $this->provision('ERPify')->getStatusCode());
        $this->assertSame(Command::FAILURE, $this->provision('Second Corp')->getStatusCode());
    }

    public function testCreatingAnAdministratorWithoutAnOrganizationLeavesNoOrphanUser(): void
    {
        $this->assertSame(Command::FAILURE, $this->createAdministrator('orphan@erpify.test')->getStatusCode());

        $this->entityManager->clear();
        $this->assertNotInstanceOf(User::class, $this->users->findByEmail(Email::from('orphan@erpify.test')));
    }

    public function testRejectsABlankOrganizationName(): void
    {
        $this->assertSame(Command::INVALID, $this->provision('   ')->getStatusCode());
    }

    public function testRejectsAnAdministratorWithAnEmptyPassword(): void
    {
        $tester = new CommandTester($this->application->find('organization:administrator:create'));
        $tester->setInputs(['']);

        $tester->execute(['email' => 'no-password@erpify.test']);

        $this->assertSame(Command::INVALID, $tester->getStatusCode());
    }

    private function provision(string $name): CommandTester
    {
        $tester = new CommandTester($this->application->find('organization:provision'));
        $tester->execute(['name' => $name]);

        return $tester;
    }

    private function createAdministrator(string $email): CommandTester
    {
        $tester = new CommandTester($this->application->find('organization:administrator:create'));
        $tester->execute(['email' => $email, 'password' => 's3cret-admin-pass']);

        return $tester;
    }

    /**
     * The two halves of "the installation has an administrator" live in different aggregates: the ADMIN role
     * on `identity_user` (asserted above) and the belonging on `membership`. Reading the member list back
     * from the organization side is what proves the second half was committed under the very organization
     * the membership names, rather than merely that a row with some org id exists.
     */
    private function assertAdminBelongsToTheOrganization(string $organizationId, string $adminId): void
    {
        $members = \array_filter(
            $this->memberships->findByOrganizationId($organizationId),
            static fn (Membership $membership): bool => $membership->userId() === $adminId,
        );

        $this->assertNotEmpty($members, 'the administrator must belong to the provisioned organization');
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

    private function truncate(): void
    {
        $this->connection->executeStatement(self::TRUNCATE_SQL);
    }
}
