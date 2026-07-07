<?php

declare(strict_types=1);

namespace Erpify\Tests\Functional\Iam\Identity\Infrastructure\Security;

use Erpify\Iam\Identity\Domain\Enum\Role;
use Erpify\Iam\Identity\Infrastructure\Security\PermissionVoter;
use Erpify\Iam\Identity\Infrastructure\Security\SecurityUser;
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
}
