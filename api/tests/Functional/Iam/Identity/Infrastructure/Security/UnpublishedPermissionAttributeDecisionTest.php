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
 * An attribute shaped like a permission that no catalog publishes, decided by the real, wired voter set: no
 * voter claims it, so the decision manager denies it to everybody, an ADMIN included.
 *
 * @internal
 */
#[CoversClass(PermissionVoter::class)]
final class UnpublishedPermissionAttributeDecisionTest extends WebTestCase
{
    /**
     * Dotted, published by nobody. The tier policy would grant it to an ADMIN (`ADMIN => ['*']`), so a voter
     * claiming it by shape would answer GRANT and, under `affirmative`, overrule any voter that owns the
     * attribute and says no. Claimed only when published, it draws no vote at all and the manager denies it —
     * fail-closed.
     */
    public function testADottedAttributeNoCatalogPublishesIsDeniedEvenToAnAdmin(): void
    {
        $client = self::createClient();

        $admin = UserFixtureFactory::create(
            Uuid::v7()->toRfc4122(),
            'unpublished-admin@erpify.test',
            'unpublished-admin-password',
            [Role::ADMIN->value],
        );
        $client->loginUser(new SecurityUser($admin), 'main');

        $authorizationChecker = self::getContainer()->get(AuthorizationCheckerInterface::class);
        $this->assertInstanceOf(AuthorizationCheckerInterface::class, $authorizationChecker);

        $accessDecision = new AccessDecision();
        $this->assertFalse(
            $authorizationChecker->isGranted('featureFlag.read', null, $accessDecision),
            'An attribute no catalog publishes must not be granted, not even to an ADMIN.',
        );
        $this->assertSame(
            [],
            \array_values(\array_filter(
                $accessDecision->votes,
                static fn (Vote $vote): bool => VoterInterface::ACCESS_ABSTAIN !== $vote->result,
            )),
            'No voter may claim an attribute no catalog publishes.',
        );
    }
}
