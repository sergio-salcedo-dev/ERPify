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
use Erpify\Tests\Functional\ResolvesContainerServices;
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
    use ResolvesContainerServices;

    private const string TRUNCATE_SQL = 'TRUNCATE membership, organization, identity_user';

    private const string ORGANIZATION_NAME = 'ERPify';

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
        $this->assertSame(Command::SUCCESS, $this->provision(self::ORGANIZATION_NAME)->getStatusCode());
        $this->assertSame(Command::SUCCESS, $this->createAdministrator('admin@erpify.test')->getStatusCode());

        $this->entityManager->clear();

        $admin = $this->users->findByEmail(Email::from('admin@erpify.test'));
        $this->assertInstanceOf(User::class, $admin);
        $adminId = $admin->getId();
        $this->assertNotNull($adminId);
        $this->assertSame([Role::ADMIN], $admin->roles());

        $membership = $this->memberships->findByUserId($adminId);
        $this->assertInstanceOf(Membership::class, $membership);

        $this->assertMembershipNamesTheProvisionedOrganization($membership->organizationId());
    }

    public function testRejectsASecondOrganization(): void
    {
        $this->assertSame(Command::SUCCESS, $this->provision(self::ORGANIZATION_NAME)->getStatusCode());
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
     * The two halves of "the installation has an administrator" live in different aggregates: the `ADMIN` role
     * on `identity_user` (asserted above) and the belonging on `membership`. The second half is asked of the
     * `organization` rows themselves rather than by reading the member list back through the membership
     * repository. That round trip looks like a cross-check and is not one: it asks whether the row already
     * found by `user_id` is also found by the `organization_id` read off that same row, a predicate no
     * behaviour of the two commands can falsify — only a mis-mapped repository could, and this test covers
     * neither the repository nor its mapping.
     *
     * What the commands can get wrong is which organization is committed, and under what name. Measured
     * against a provision that ignores its `name` argument: the member-list round trip stays green, the
     * second assertion below goes red. The count guards the other direction — the administrator bootstrap
     * resolves the organization rather than creating one, so a second row here means it created its own
     * (that a *second* provision is refused is `testRejectsASecondOrganization`, not this).
     */
    private function assertMembershipNamesTheProvisionedOrganization(string $organizationId): void
    {
        $this->assertSame(
            1,
            $this->organizationCount('SELECT COUNT(*) FROM organization'),
            'the installation must hold exactly one organization',
        );

        // `id` is a `uuid` column, so the cast compares canonically rather than by string casing.
        $this->assertSame(
            1,
            $this->organizationCount(
                'SELECT COUNT(*) FROM organization WHERE id = CAST(:id AS UUID) AND name = :name',
                ['id' => $organizationId, 'name' => self::ORGANIZATION_NAME],
            ),
            'the membership must name the organization the provision command committed',
        );
    }

    /**
     * @param array<string, string> $parameters
     */
    private function organizationCount(string $sql, array $parameters = []): int
    {
        $count = $this->connection->fetchOne($sql, $parameters);
        \assert(\is_numeric($count));

        return (int) $count;
    }

    private function truncate(): void
    {
        $this->connection->executeStatement(self::TRUNCATE_SQL);
    }
}
