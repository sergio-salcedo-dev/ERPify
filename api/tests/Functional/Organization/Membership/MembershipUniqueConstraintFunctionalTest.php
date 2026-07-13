<?php

declare(strict_types=1);

namespace Erpify\Tests\Functional\Organization\Membership;

use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Erpify\Iam\Identity\Domain\Enum\Role;
use Erpify\Organization\Membership\Domain\Entity\Membership;
use Erpify\Organization\Membership\Domain\Exception\UserAlreadyMember;
use Erpify\Organization\Membership\Domain\Repository\MembershipRepository;
use Erpify\Organization\Membership\Infrastructure\Persistence\Doctrine\DoctrineMembershipRepository;
use Erpify\Organization\Organization\Domain\Entity\Organization;
use Erpify\Organization\Organization\Domain\Repository\OrganizationRepository;
use Erpify\Shared\Uuid\Domain\Uuid;
use Override;
use PHPUnit\Framework\Attributes\CoversClass;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * The concurrent grant-duplicate backstop the application pre-check cannot close (two grants both pass the
 * "already a member?" read, then collide on INSERT): the adapter translates the `user_id` UNIQUE breach into
 * the domain {@see UserAlreadyMember} rather than leaking a raw DBAL error. Driven
 * against REAL Postgres by writing a second membership for the same user directly through the port, bypassing
 * the application pre-check.
 *
 * @internal
 */
#[CoversClass(DoctrineMembershipRepository::class)]
final class MembershipUniqueConstraintFunctionalTest extends KernelTestCase
{
    private const string TRUNCATE_SQL = 'TRUNCATE membership, organization, identity_user CASCADE';

    private Connection $connection;

    private string $organizationId;

    #[Override]
    protected function setUp(): void
    {
        self::bootKernel();

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

    public function testASecondMembershipForTheSameUserIsTranslatedToUserAlreadyMember(): void
    {
        $userId = Uuid::generate();
        $memberships = $this->service(MembershipRepository::class);

        $memberships->save(Membership::grant(Uuid::generate(), $userId, $this->organizationId, Role::VIEWER));

        $this->expectException(UserAlreadyMember::class);

        $memberships->save(Membership::grant(Uuid::generate(), $userId, $this->organizationId, Role::EDITOR));
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
