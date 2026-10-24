<?php

declare(strict_types=1);

namespace Erpify\Tests\Functional\Iam\Identity\Infrastructure\Security;

use Erpify\Iam\Identity\Infrastructure\Security\PermissionVoter;
use Erpify\Iam\Identity\Infrastructure\Security\SecurityUser;
use Erpify\Shared\Access\Domain\Role;
use Erpify\Tests\DataFixtures\UserFixtureFactory;
use PHPUnit\Framework\Attributes\CoversClass;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Security\Core\Authorization\AccessDecision;
use Symfony\Component\Security\Core\Authorization\AuthorizationCheckerInterface;
use Symfony\Component\Security\Core\Authorization\Voter\Vote;
use Symfony\Component\Security\Core\Authorization\Voter\VoterInterface;
use Symfony\Component\Uid\Uuid;

/**
 * Proves the access-decision composition on the real, wired voter set. With the default `affirmative`
 * strategy left untouched, an authenticated VIEWER — who clears the firewall's IS_AUTHENTICATED_FULLY — is
 * denied `bank.write` (the native voters abstain on a permission attribute, so the {@see PermissionVoter}'s
 * DENY stands) yet granted `bank.read`: the decision every `#[IsGranted('bank.write')]` route takes, read here
 * off the checker without going through one.
 *
 * @internal
 */
#[CoversClass(PermissionVoter::class)]
final class PermissionVoterAccessDecisionTest extends WebTestCase
{
    /**
     * {@see PermissionVoter::supports()} claims every `<resource>.<action>` attribute by shape alone, which is
     * sound only while the decision manager composes votes `affirmative`ly: under `unanimous` or `consensus`
     * the voter's DENY on an attribute it claimed would weigh against another voter's GRANT in the same
     * decision. Read off the decision the wired manager actually took, not off `security.yaml`, so a strategy
     * set in any config file, environment block or `strategy_service` is seen. Declaring
     * `access_decision_manager.strategy` as anything else reds the first assertion; a second voter claiming a
     * permission attribute — the contention this voter's shape-only support assumes away — reds the second. A
     * voter the manager merely consults and that abstains is not contention, so abstentions are left out.
     */
    public function testPermissionAttributesAreDecidedAffirmativelyWithThePermissionVoterAsTheirOnlyVoter(): void
    {
        $client = self::createClient();

        $viewer = UserFixtureFactory::create(
            Uuid::v7()->toRfc4122(),
            'strategy-viewer@erpify.test',
            'strategy-viewer-password',
            [Role::VIEWER->value],
        );
        $client->loginUser(new SecurityUser($viewer), 'main');

        $authorizationChecker = self::getContainer()->get(AuthorizationCheckerInterface::class);
        $this->assertInstanceOf(AuthorizationCheckerInterface::class, $authorizationChecker);

        $accessDecision = new AccessDecision();
        $this->assertFalse(
            $authorizationChecker->isGranted('bank.write', null, $accessDecision),
            'A VIEWER must not be granted bank.write.',
        );

        $this->assertSame(
            'affirmative',
            $accessDecision->strategy,
            'The access decision manager no longer decides affirmatively. PermissionVoter::supports() claims '
            . 'every <resource>.<action> attribute by shape, which is sound only under affirmative; change '
            . 'the voter (a positive marker or a resource registry) before changing the strategy.',
        );

        $this->assertSame(
            [[PermissionVoter::class, VoterInterface::ACCESS_DENIED]],
            \array_values(\array_map(
                static fn (Vote $vote): array => [$vote->voter, $vote->result],
                \array_filter(
                    $accessDecision->votes,
                    static fn (Vote $vote): bool => VoterInterface::ACCESS_ABSTAIN !== $vote->result,
                ),
            )),
            'A permission attribute must be answered by PermissionVoter alone; another voter now casts a vote on it, '
            . 'so the two contend over the same attribute.',
        );
    }

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

    public function testUsersGrantAdminIsGrantedToAnAdminButDeniedToAGenericTier(): void
    {
        $client = self::createClient();

        $authorizationChecker = self::getContainer()->get(AuthorizationCheckerInterface::class);
        $this->assertInstanceOf(AuthorizationCheckerInterface::class, $authorizationChecker);

        // Handing out ADMIN is named as its own act, checked imperatively by the invite and role-change
        // controllers only when the submitted set carries that role. This is the wired end of that check: no
        // generic tier reaches it, and an ADMIN does — the row is non-empty on purpose, because both
        // endpoints that can delegate are already ADMIN-only and an empty one would make a second
        // administrator impossible to create.
        $manager = UserFixtureFactory::create(
            Uuid::v7()->toRfc4122(),
            'grant-admin-manager@erpify.test',
            'grant-admin-manager-password',
            [Role::MANAGER->value],
        );
        $client->loginUser(new SecurityUser($manager), 'main');
        $this->assertFalse(
            $authorizationChecker->isGranted('users.grantAdmin'),
            'A generic MANAGER tier must not be granted users.grantAdmin.',
        );

        $admin = UserFixtureFactory::create(
            Uuid::v7()->toRfc4122(),
            'grant-admin-admin@erpify.test',
            'grant-admin-admin-password',
            [Role::ADMIN->value],
        );
        $client->loginUser(new SecurityUser($admin), 'main');
        $this->assertTrue(
            $authorizationChecker->isGranted('users.grantAdmin'),
            'An ADMIN must be granted users.grantAdmin, or no second administrator could ever be created.',
        );
    }

    public function testUsersRevokeInvitationIsGrantedToAnAdminButDeniedToAGenericTier(): void
    {
        $client = self::createClient();

        $authorizationChecker = self::getContainer()->get(AuthorizationCheckerInterface::class);
        $this->assertInstanceOf(AuthorizationCheckerInterface::class, $authorizationChecker);

        // Pulling a live invitation back cancels somebody's onboarding, so it stays on the same ADMIN-only
        // footing as the invite that created it.
        $manager = UserFixtureFactory::create(
            Uuid::v7()->toRfc4122(),
            'revoke-invitation-manager@erpify.test',
            'revoke-invitation-manager-password',
            [Role::MANAGER->value],
        );
        $client->loginUser(new SecurityUser($manager), 'main');
        $this->assertFalse(
            $authorizationChecker->isGranted('users.revokeInvitation'),
            'A generic MANAGER tier must not be granted users.revokeInvitation.',
        );

        $admin = UserFixtureFactory::create(
            Uuid::v7()->toRfc4122(),
            'revoke-invitation-admin@erpify.test',
            'revoke-invitation-admin-password',
            [Role::ADMIN->value],
        );
        $client->loginUser(new SecurityUser($admin), 'main');
        $this->assertTrue(
            $authorizationChecker->isGranted('users.revokeInvitation'),
            'An ADMIN must be granted users.revokeInvitation through its explicit grant.',
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
