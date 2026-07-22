<?php

declare(strict_types=1);

namespace Erpify\Tests\Functional\Iam\Identity\Infrastructure\Security;

use Erpify\Iam\Identity\Infrastructure\Security\PermissionVoter;
use Erpify\Iam\Identity\Infrastructure\Security\SecurityUser;
use Erpify\Shared\Access\Domain\Role;
use Erpify\Tests\DataFixtures\UserFixtureFactory;
use PHPUnit\Framework\Attributes\CoversClass;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Security\Core\Authorization\AuthorizationCheckerInterface;
use Symfony\Component\Uid\Uuid;

/**
 * Proves the access-decision composition on the real, wired voter set. With the default `affirmative`
 * strategy left untouched and no route gated yet, an authenticated VIEWER — who clears the firewall's
 * IS_AUTHENTICATED_FULLY — is denied `bank.write` (the native voters abstain on a permission attribute, so
 * the {@see PermissionVoter}'s DENY stands) yet granted `bank.read`. This is exactly what a future
 * `#[IsGranted('bank.write')]` will enforce, verified here without opening a route.
 *
 * @internal
 */
#[CoversClass(PermissionVoter::class)]
final class PermissionVoterAccessDecisionTest extends WebTestCase
{
    public function testAnAuthenticatedViewerIsDeniedWriteButGrantedRead(): void
    {
        $client = self::createClient();

        $viewer = UserFixtureFactory::create(
            Uuid::v7()->toRfc4122(),
            'viewer@erpify.test',
            'viewer-password',
            [Role::VIEWER->value],
        );
        $client->loginUser(new SecurityUser($viewer), 'main');

        $authorizationChecker = self::getContainer()->get(AuthorizationCheckerInterface::class);
        $this->assertInstanceOf(AuthorizationCheckerInterface::class, $authorizationChecker);

        // Independence: the VIEWER clears the firewall (IS_AUTHENTICATED_FULLY), yet that is a separate
        // decision from the permission — so the write is still denied and only the read granted.
        $this->assertTrue(
            $authorizationChecker->isGranted('IS_AUTHENTICATED_FULLY'),
            'The VIEWER must clear the firewall, so the permission decision is provably independent of it.',
        );
        $this->assertFalse($authorizationChecker->isGranted('bank.write'), 'A VIEWER must not be granted bank.write.');
        $this->assertTrue($authorizationChecker->isGranted('bank.read'), 'A VIEWER must be granted bank.read.');
    }

    public function testAuditTrailReadIsGrantedToAnAuditReaderButDeniedToAGenericTier(): void
    {
        $client = self::createClient();

        $authorizationChecker = self::getContainer()->get(AuthorizationCheckerInterface::class);
        $this->assertInstanceOf(AuthorizationCheckerInterface::class, $authorizationChecker);

        $auditReader = UserFixtureFactory::create(
            Uuid::v7()->toRfc4122(),
            'audit-reader@erpify.test',
            'audit-reader-password',
            [Role::AUDIT_READER->value],
        );
        $client->loginUser(new SecurityUser($auditReader), 'main');
        $this->assertTrue(
            $authorizationChecker->isGranted('auditTrail.read'),
            'An AUDIT_READER must be granted auditTrail.read.',
        );

        // A MANAGER holds the delete tier (which includes read), yet the trail opts out of tier auto-grant,
        // so a generic business tier is still refused — sensitive access is explicit-only.
        $manager = UserFixtureFactory::create(
            Uuid::v7()->toRfc4122(),
            'manager@erpify.test',
            'manager-password',
            [Role::MANAGER->value],
        );
        $client->loginUser(new SecurityUser($manager), 'main');
        $this->assertFalse(
            $authorizationChecker->isGranted('auditTrail.read'),
            'A generic MANAGER tier must not be granted auditTrail.read.',
        );
    }

    public function testAuditTrailReadIsGrantedToAnAdminThroughTheSuperuserClause(): void
    {
        $client = self::createClient();

        $authorizationChecker = self::getContainer()->get(AuthorizationCheckerInterface::class);
        $this->assertInstanceOf(AuthorizationCheckerInterface::class, $authorizationChecker);

        // auditTrail opts out of tiering, so ADMIN's wildcard tier does not reach it and the explicit grant is
        // what does. This pins the full wired chain (ROLE_ADMIN → bareRoleTokens → policy), which the
        // pure-policy unit test cannot exercise.
        $admin = UserFixtureFactory::create(
            Uuid::v7()->toRfc4122(),
            'admin@erpify.test',
            'admin-password',
            [Role::ADMIN->value],
        );
        $client->loginUser(new SecurityUser($admin), 'main');
        $this->assertTrue(
            $authorizationChecker->isGranted('auditTrail.read'),
            'An ADMIN must be granted auditTrail.read through the superuser clause.',
        );
    }

    public function testUsersReadIsGrantedToAnAdminButDeniedToAGenericTier(): void
    {
        $client = self::createClient();

        $authorizationChecker = self::getContainer()->get(AuthorizationCheckerInterface::class);
        $this->assertInstanceOf(AuthorizationCheckerInterface::class, $authorizationChecker);

        // The identity console opts out of tier auto-grant, so a fully-tiered MANAGER is refused users.read —
        // only ADMIN reaches it, through its explicit grant. This pins the wired chain (ROLE_ADMIN →
        // bareRoleTokens → policy), the same admin-only shape as auditTrail but for the users resource.
        $manager = UserFixtureFactory::create(
            Uuid::v7()->toRfc4122(),
            'users-manager@erpify.test',
            'users-manager-password',
            [Role::MANAGER->value],
        );
        $client->loginUser(new SecurityUser($manager), 'main');
        $this->assertFalse(
            $authorizationChecker->isGranted('users.read'),
            'A generic MANAGER tier must not be granted users.read.',
        );

        $admin = UserFixtureFactory::create(
            Uuid::v7()->toRfc4122(),
            'users-admin@erpify.test',
            'users-admin-password',
            [Role::ADMIN->value],
        );
        $client->loginUser(new SecurityUser($admin), 'main');
        $this->assertTrue(
            $authorizationChecker->isGranted('users.read'),
            'An ADMIN must be granted users.read through the superuser clause.',
        );
    }

    public function testUsersChangeStatusIsGrantedToAnAdminButDeniedToAGenericTier(): void
    {
        $client = self::createClient();

        $authorizationChecker = self::getContainer()->get(AuthorizationCheckerInterface::class);
        $this->assertInstanceOf(AuthorizationCheckerInterface::class, $authorizationChecker);

        // changeStatus on the identity console is ADMIN-only: users opts out of tier auto-grant and the
        // permission carries no lesser explicit grant, so even a fully-tiered MANAGER is refused...
        $manager = UserFixtureFactory::create(
            Uuid::v7()->toRfc4122(),
            'status-users-manager@erpify.test',
            'status-users-manager-password',
            [Role::MANAGER->value],
        );
        $client->loginUser(new SecurityUser($manager), 'main');
        $this->assertFalse(
            $authorizationChecker->isGranted('users.changeStatus'),
            'A generic MANAGER tier must not be granted users.changeStatus.',
        );

        // ...while an ADMIN reaches it through its explicit grant, the same admin-only shape as users.read but
        // for the write action the console gates the suspend/deactivate control on.
        $admin = UserFixtureFactory::create(
            Uuid::v7()->toRfc4122(),
            'status-users-admin@erpify.test',
            'status-users-admin-password',
            [Role::ADMIN->value],
        );
        $client->loginUser(new SecurityUser($admin), 'main');
        $this->assertTrue(
            $authorizationChecker->isGranted('users.changeStatus'),
            'An ADMIN must be granted users.changeStatus through the superuser clause.',
        );
    }

    public function testUsersChangeRolesIsGrantedToAnAdminButDeniedToAGenericTier(): void
    {
        $client = self::createClient();

        $authorizationChecker = self::getContainer()->get(AuthorizationCheckerInterface::class);
        $this->assertInstanceOf(AuthorizationCheckerInterface::class, $authorizationChecker);

        // Assigning roles is the console's most privileged write — it can mint an administrator — so it stays
        // ADMIN-only: users opts out of tier auto-grant and the permission carries no lesser explicit grant.
        $manager = UserFixtureFactory::create(
            Uuid::v7()->toRfc4122(),
            'roles-users-manager@erpify.test',
            'roles-users-manager-password',
            [Role::MANAGER->value],
        );
        $client->loginUser(new SecurityUser($manager), 'main');
        $this->assertFalse(
            $authorizationChecker->isGranted('users.changeRoles'),
            'A generic MANAGER tier must not be granted users.changeRoles.',
        );

        $admin = UserFixtureFactory::create(
            Uuid::v7()->toRfc4122(),
            'roles-users-admin@erpify.test',
            'roles-users-admin-password',
            [Role::ADMIN->value],
        );
        $client->loginUser(new SecurityUser($admin), 'main');
        $this->assertTrue(
            $authorizationChecker->isGranted('users.changeRoles'),
            'An ADMIN must be granted users.changeRoles through the superuser clause.',
        );
    }

    public function testBankAccountChangeStatusIsGrantedToAManagerButDeniedToAnEditor(): void
    {
        $client = self::createClient();

        $authorizationChecker = self::getContainer()->get(AuthorizationCheckerInterface::class);
        $this->assertInstanceOf(AuthorizationCheckerInterface::class, $authorizationChecker);

        // changeStatus is a domain operation reached only by the explicit grant, so a MANAGER holds it...
        $manager = UserFixtureFactory::create(
            Uuid::v7()->toRfc4122(),
            'status-manager@erpify.test',
            'status-manager-password',
            [Role::MANAGER->value],
        );
        $client->loginUser(new SecurityUser($manager), 'main');
        $this->assertTrue(
            $authorizationChecker->isGranted('bankAccount.changeStatus'),
            'A MANAGER must be granted bankAccount.changeStatus through the explicit grant.',
        );

        // ...while an EDITOR, though it clears the write tier, is refused — write does not imply
        // changeStatus. This pins the wired PermissionVoter → policy → explicitGrants chain end to end,
        // not just the pure policy the unit test exercises.
        $editor = UserFixtureFactory::create(
            Uuid::v7()->toRfc4122(),
            'status-editor@erpify.test',
            'status-editor-password',
            [Role::EDITOR->value],
        );
        $client->loginUser(new SecurityUser($editor), 'main');
        $this->assertFalse(
            $authorizationChecker->isGranted('bankAccount.changeStatus'),
            'An EDITOR must not be granted bankAccount.changeStatus despite holding the write tier.',
        );
    }
}
