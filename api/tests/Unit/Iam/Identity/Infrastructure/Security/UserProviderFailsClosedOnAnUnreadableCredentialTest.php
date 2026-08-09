<?php

declare(strict_types=1);

namespace Erpify\Tests\Unit\Iam\Identity\Infrastructure\Security;

use Erpify\Iam\Identity\Domain\Entity\User;
use Erpify\Iam\Identity\Infrastructure\Security\UserProvider;
use Erpify\Tests\Unit\Iam\Identity\Application\CountingPreIdentityTimingFloor;
use Erpify\Tests\Unit\Iam\Identity\Application\InMemoryUserRepository;
use Erpify\Tests\Unit\Iam\Identity\Domain\Entity\Mother\UserMother;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use ReflectionProperty;
use Symfony\Component\Security\Core\Exception\UserNotFoundException;

/**
 * The provider's own fail-closed, kept apart from its lookup behaviour because it answers a different
 * question — not "who is this identifier" but "may this row authenticate at all".
 *
 * @internal
 */
#[CoversClass(UserProvider::class)]
final class UserProviderFailsClosedOnAnUnreadableCredentialTest extends TestCase
{
    /**
     * The credential is re-hydrated HERE, eagerly, so a stored hash the value object refuses becomes a
     * not-found at the boundary that owns "may this identity authenticate" — rather than escaping from inside
     * the firewall later, where it carries no marker and lands as a 500.
     *
     * Asserted at this level and not only through a scenario because every route that carries a session
     * reaches `SecurityUser::isEqualTo()` first, which refuses too: with only those, this guard could be
     * deleted and the suite would stay green. The timing floor is part of the claim — the refusal has to be
     * indistinguishable from an unknown email in latency as well as in body.
     */
    public function testRefusesAStoredCredentialTheValueObjectCannotRead(): void
    {
        $user = UserMother::create();
        (new ReflectionProperty(User::class, 'passwordHash'))->setValue($user, '');
        $timingFloor = new CountingPreIdentityTimingFloor();
        $provider = new UserProvider(new InMemoryUserRepository($user), $timingFloor);

        try {
            $provider->loadUserByIdentifier(UserMother::DEFAULT_EMAIL);
            $this->fail('An unreadable stored credential must not resolve to a usable identity.');
        } catch (UserNotFoundException) {
            $this->assertSame(1, $timingFloor->invocations, 'the refusal pays the same floor an unknown email does');
        }
    }
}
