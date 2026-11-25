<?php

declare(strict_types=1);

namespace Erpify\Tests\Unit\Iam\Identity\Infrastructure\Controller;

use Erpify\Iam\Identity\Application\FulfilIdentityErasure;
use Erpify\Iam\Identity\Application\ProveActorPassword;
use Erpify\Iam\Identity\Application\ProveCurrentPassword;
use Erpify\Iam\Identity\Domain\Exception\InvalidCurrentPassword;
use Erpify\Iam\Identity\Domain\HashedPassword;
use Erpify\Iam\Identity\Infrastructure\Controller\UserEraseController;
use Erpify\Iam\Identity\Infrastructure\Http\EraseIdentityRequest;
use Erpify\Iam\Identity\Infrastructure\Security\CurrentPasswordProofThrottle;
use Erpify\Iam\Identity\Infrastructure\Security\PasswordHasher;
use Erpify\Iam\Identity\Infrastructure\Security\SecurityUser;
use Erpify\Shared\ErrorContract\Domain\Exception\RateLimitExceeded;
use Erpify\Tests\Unit\Iam\Identity\Application\InMemoryUserRepository;
use Erpify\Tests\Unit\Iam\Identity\Domain\Entity\Mother\UserMother;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\PasswordHasher\Hasher\PasswordHasherFactory;
use Symfony\Component\RateLimiter\RateLimiterFactory;
use Symfony\Component\RateLimiter\Storage\InMemoryStorage;

/**
 * What it costs to erase somebody's identity, asserted where the cost is assembled: the real credential proof and
 * the real limiter over in-memory storage. The property is ORDER — budget, then proof, then the erasure — and the
 * erasure use case is deliberately left UNCONSTRUCTED: it is `final` with a dozen collaborators, and an object
 * whose properties were never initialised fails loudly (`Error`) the moment anything calls it. A refusal that
 * comes back as the proof's own exception is therefore evidence that nothing downstream ran.
 *
 * Only the failure paths are driven; the erasure itself and the 204 are the functional and Behat lanes'.
 *
 * @internal
 */
#[CoversClass(UserEraseController::class)]
final class UserEraseControllerTest extends TestCase
{
    private const string PASSWORD = 'the-current-password';

    private const string TARGET_ID = '0190a1b2-c3d4-7e5f-8a9b-0c1d2e3f4a5d';

    #[Test]
    public function aWrongPasswordRefusesBeforeTheErasureIsReached(): void
    {
        $controller = $this->controller($this->limiter(5));

        $this->expectException(InvalidCurrentPassword::class);

        $controller($this->caller(), self::TARGET_ID, new EraseIdentityRequest('not-it'));
    }

    #[Test]
    public function aMalformedTargetIdIsRefusedOnTheProofAndNeverAsAnInvalidId(): void
    {
        $controller = $this->controller($this->limiter(5));

        // Were the id checked first, this caller would learn what is and is not a well-formed target without
        // proving anything. The erasure use case is where that check lives, and it is never reached.
        $this->expectException(InvalidCurrentPassword::class);

        $controller($this->caller(), 'not-a-uuid', new EraseIdentityRequest('not-it'));
    }

    #[Test]
    public function aSpentBudgetRefusesBeforeThePasswordIsEvenChecked(): void
    {
        $controller = $this->controller($this->limiter(1));

        try {
            $controller($this->caller(), self::TARGET_ID, new EraseIdentityRequest('spends-the-only-token'));
        } catch (InvalidCurrentPassword) {
            // The budget is spent by the attempt, not by its outcome — which is what leaves none for below.
        }

        // The CORRECT password now: only a refusal on the budget keeps this from reaching the erasure.
        $this->expectException(RateLimitExceeded::class);

        $controller($this->caller(), self::TARGET_ID, new EraseIdentityRequest(self::PASSWORD));
    }

    private function caller(): SecurityUser
    {
        return new SecurityUser(UserMother::create(password: HashedPassword::fromHash(self::PASSWORD)));
    }

    private function limiter(int $budget): RateLimiterFactory
    {
        return new RateLimiterFactory(
            ['id' => 'per_identity', 'policy' => 'sliding_window', 'limit' => $budget, 'interval' => '15 minutes'],
            new InMemoryStorage(),
        );
    }

    private function controller(RateLimiterFactory $limiter): UserEraseController
    {
        $users = new InMemoryUserRepository();
        $users->seeded[] = UserMother::create(password: HashedPassword::fromHash(self::PASSWORD));

        return new UserEraseController(
            (new ReflectionClass(FulfilIdentityErasure::class))->newInstanceWithoutConstructor(),
            new ProveActorPassword($users, new ProveCurrentPassword()),
            new PasswordHasher(new PasswordHasherFactory([SecurityUser::class => ['algorithm' => 'plaintext']])),
            new CurrentPasswordProofThrottle($limiter, new RequestStack()),
        );
    }
}
