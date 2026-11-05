<?php

declare(strict_types=1);

namespace Erpify\Tests\Unit\Iam\Identity\Infrastructure\Controller;

use Erpify\Iam\Identity\Application\ChangeUserStatus;
use Erpify\Iam\Identity\Application\ProveActorPassword;
use Erpify\Iam\Identity\Application\ProveCurrentPassword;
use Erpify\Iam\Identity\Application\RevokeSessionsBestEffort;
use Erpify\Iam\Identity\Domain\Enum\IdentityStatus;
use Erpify\Iam\Identity\Domain\Exception\InvalidCurrentPassword;
use Erpify\Iam\Identity\Domain\HashedPassword;
use Erpify\Iam\Identity\Infrastructure\Controller\UserPatchStatusController;
use Erpify\Iam\Identity\Infrastructure\Http\ChangeUserStatusRequest;
use Erpify\Iam\Identity\Infrastructure\Http\UserResourceMapper;
use Erpify\Iam\Identity\Infrastructure\Security\CurrentPasswordProofThrottle;
use Erpify\Iam\Identity\Infrastructure\Security\PasswordHasher;
use Erpify\Iam\Identity\Infrastructure\Security\SecurityUser;
use Erpify\Iam\Session\Application\RevokeAllSessions;
use Erpify\Shared\Access\Domain\Role;
use Erpify\Shared\Audit\Domain\ActorContext;
use Erpify\Shared\Clock\Domain\SystemClock;
use Erpify\Shared\ErrorContract\Domain\Exception\RateLimitExceeded;
use Erpify\Shared\Http\Infrastructure\Responder\ResourceResponder;
use Erpify\Tests\Double\Clock\FixedClock;
use Erpify\Tests\Unit\Iam\Identity\Application\InlineTransactionManager;
use Erpify\Tests\Unit\Iam\Identity\Application\InMemoryActiveAdministratorDirectory;
use Erpify\Tests\Unit\Iam\Identity\Application\InMemoryUserRepository;
use Erpify\Tests\Unit\Iam\Identity\Application\RecordingEventBus;
use Erpify\Tests\Unit\Iam\Identity\Domain\Entity\Mother\UserMother;
use Erpify\Tests\Unit\Iam\Session\Application\InMemorySessionRepository;
use Erpify\Tests\Unit\Iam\Session\Application\RecordingSessionSubjectLock;
use Erpify\Tests\Unit\Shared\Audit\Infrastructure\Double\FixedActorContextFactory;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use ReflectionClass;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\PasswordHasher\Hasher\PasswordHasherFactory;
use Symfony\Component\RateLimiter\RateLimiterFactory;
use Symfony\Component\RateLimiter\Storage\InMemoryStorage;

/**
 * What it costs to suspend or deactivate somebody, asserted where the cost is assembled: the real use case,
 * the real credential proof and the real limiter over in-memory storage. The properties are about ORDER
 * between collaborators — budget, then proof, then the write — so a double that answered on a schedule of its
 * own would make each of them a property of the double.
 *
 * Only the failure paths are driven: the success response needs the serializer, which these properties do not,
 * and the wired success is the functional test's.
 *
 * @internal
 *
 * @SuppressWarnings("PHPMD.CouplingBetweenObjects") the price of weaving the real endpoint rather than mocking
 * it, for the reason the sibling recovery-secret controller tests state
 */
#[CoversClass(UserPatchStatusController::class)]
final class UserPatchStatusControllerTest extends TestCase
{
    private const string PASSWORD = 'the-current-password';

    private const string TARGET_ID = '0190a1b2-c3d4-7e5f-8a9b-0c1d2e3f4a5d';

    protected function setUp(): void
    {
        parent::setUp();
        SystemClock::set(FixedClock::at('2026-11-05T12:00:00+00:00'));
    }

    #[Test]
    public function aWrongPasswordRefusesAndLeavesTheStatusAlone(): void
    {
        $users = $this->users();
        $controller = $this->controller($users, $this->limiter(5));

        try {
            $controller(
                $this->caller(),
                self::TARGET_ID,
                new ChangeUserStatusRequest(IdentityStatus::SUSPENDED, 'not-it'),
            );
            $this->fail('A wrong current password must be refused.');
        } catch (InvalidCurrentPassword) {
            $this->assertSame([], $users->saved, 'a transition was written for a caller who failed the proof');
        }
    }

    #[Test]
    public function aSpentBudgetRefusesBeforeTheUseCaseRunsAtAll(): void
    {
        $users = $this->users();
        $controller = $this->controller($users, $this->limiter(1));

        try {
            $controller(
                $this->caller(),
                self::TARGET_ID,
                new ChangeUserStatusRequest(IdentityStatus::SUSPENDED, 'spends-it'),
            );
        } catch (InvalidCurrentPassword) {
            // The budget is spent by the attempt, not by its outcome — which is what leaves none for below.
        }

        $this->expectException(RateLimitExceeded::class);

        try {
            $controller(
                $this->caller(),
                self::TARGET_ID,
                new ChangeUserStatusRequest(IdentityStatus::SUSPENDED, self::PASSWORD),
            );
        } finally {
            $this->assertSame([], $users->saved, 'the status changed while the caller was over budget');
        }
    }

    private function caller(): SecurityUser
    {
        return new SecurityUser(UserMother::create(password: HashedPassword::fromHash(self::PASSWORD)));
    }

    private function users(): InMemoryUserRepository
    {
        // The preset is what the use case locks and writes (the target); the actor is only READ by the proof.
        $users = new InMemoryUserRepository(
            UserMother::create(id: self::TARGET_ID, email: 'target@erpify.test', roles: [Role::VIEWER]),
        );
        $users->seeded[] = UserMother::create(password: HashedPassword::fromHash(self::PASSWORD));

        return $users;
    }

    private function limiter(int $budget): RateLimiterFactory
    {
        return new RateLimiterFactory(
            ['id' => 'per_identity', 'policy' => 'sliding_window', 'limit' => $budget, 'interval' => '15 minutes'],
            new InMemoryStorage(),
        );
    }

    private function controller(InMemoryUserRepository $users, RateLimiterFactory $limiter): UserPatchStatusController
    {
        $revoke = new RevokeSessionsBestEffort(
            new RevokeAllSessions(
                new InMemorySessionRepository(),
                new RecordingEventBus(),
                new InlineTransactionManager(),
                new FixedClock(SystemClock::now()),
                new RecordingSessionSubjectLock(),
            ),
            new NullLogger(),
        );

        return new UserPatchStatusController(
            new ChangeUserStatus(
                $users,
                new InMemoryActiveAdministratorDirectory([]),
                $revoke,
                new RecordingEventBus(),
                new InlineTransactionManager(),
                new FixedActorContextFactory(ActorContext::system()),
            ),
            new ProveActorPassword($users, new ProveCurrentPassword()),
            new PasswordHasher(new PasswordHasherFactory([SecurityUser::class => ['algorithm' => 'plaintext']])),
            new CurrentPasswordProofThrottle($limiter, new RequestStack()),
            new UserResourceMapper(),
            // Never reached on the paths driven here: each one throws before a response is built.
            (new ReflectionClass(ResourceResponder::class))->newInstanceWithoutConstructor(),
        );
    }
}
