<?php

declare(strict_types=1);

namespace Erpify\Tests\Unit\Iam\Identity\Application;

use Erpify\Iam\Identity\Application\ChangeUserStatus;
use Erpify\Iam\Identity\Domain\Enum\IdentityStatus;
use Erpify\Iam\Identity\Domain\Exception\LastActiveAdministratorProtected;
use Erpify\Iam\Identity\Domain\Exception\UserNotFound;
use Erpify\Shared\Persistence\Application\TransactionManager;
use Erpify\Tests\Unit\Iam\Identity\Domain\Entity\Mother\UserMother;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * The status-change use case enforces the "keep ≥1 active ADMIN" invariant. The target's own roles are
 * irrelevant — the {@see \Erpify\Iam\Identity\Domain\Repository\ActiveAdministratorDirectory} is the sole
 * authority on who counts, so these tests drive that seam through its in-memory spec.
 *
 * @internal
 */
#[CoversClass(ChangeUserStatus::class)]
final class ChangeUserStatusTest extends TestCase
{
    private const string OTHER_ADMIN_ID = '0190a1b2-c3d4-7e5f-8a9b-0c1d2e3f4a70';

    private const string GHOST_ADMIN_ID = '0190a1b2-c3d4-7e5f-8a9b-0c1d2e3f4a71';

    public function testSuspendingTheLastActiveAdministratorIsRejectedWithoutSavingOrEmitting(): void
    {
        $user = UserMother::create();
        $repository = new InMemoryUserRepository($user);
        $eventBus = new RecordingEventBus();
        $directory = new InMemoryActiveAdministratorDirectory([UserMother::DEFAULT_ID => true]);

        try {
            $this->makeUseCase($repository, $directory, $eventBus)->suspend(UserMother::DEFAULT_ID);
            $this->fail('Expected LastActiveAdministratorProtected.');
        } catch (LastActiveAdministratorProtected) {
            // rejected before any mutation, save or event
        }

        $this->assertSame(IdentityStatus::ACTIVE, $user->status());
        $this->assertSame([], $repository->saved);
        $this->assertSame([], $eventBus->publishedEvents);
    }

    public function testSuspendingAnAdminWhileOtherActiveAdminsRemainAppliesAndPublishes(): void
    {
        $user = UserMother::create();
        $repository = new InMemoryUserRepository($user);
        $eventBus = new RecordingEventBus();
        $directory = new InMemoryActiveAdministratorDirectory([
            UserMother::DEFAULT_ID => true,
            self::OTHER_ADMIN_ID => true,
        ]);

        $changed = $this->makeUseCase($repository, $directory, $eventBus)->suspend(UserMother::DEFAULT_ID);

        $this->assertSame(IdentityStatus::SUSPENDED, $changed->status());
        $this->assertSame([$user], $repository->saved);
        $this->assertCount(1, $eventBus->publishedEvents);
    }

    public function testDeactivatingAnAdminWhileOtherActiveAdminsRemainAppliesAndPublishes(): void
    {
        $user = UserMother::create();
        $repository = new InMemoryUserRepository($user);
        $eventBus = new RecordingEventBus();
        $directory = new InMemoryActiveAdministratorDirectory([
            UserMother::DEFAULT_ID => true,
            self::OTHER_ADMIN_ID => true,
        ]);

        $changed = $this->makeUseCase($repository, $directory, $eventBus)->deactivate(UserMother::DEFAULT_ID);

        $this->assertSame(IdentityStatus::DEACTIVATED, $changed->status());
        $this->assertCount(1, $eventBus->publishedEvents);
    }

    public function testAPhantomAdminMembershipDoesNotRescueTheLastActiveAdministrator(): void
    {
        // A membership still marked ADMIN whose backing user was hard-deleted (or is no longer ACTIVE) must
        // NOT count — otherwise a ghost membership would let the last real administrator be deactivated.
        $user = UserMother::create();
        $repository = new InMemoryUserRepository($user);
        $eventBus = new RecordingEventBus();
        $directory = new InMemoryActiveAdministratorDirectory([
            UserMother::DEFAULT_ID => true,
            self::GHOST_ADMIN_ID => false,
        ]);

        $this->expectException(LastActiveAdministratorProtected::class);

        $this->makeUseCase($repository, $directory, $eventBus)->deactivate(UserMother::DEFAULT_ID);
    }

    public function testChangingTheStatusOfAMissingIdentityIsANotFound(): void
    {
        $repository = new InMemoryUserRepository();
        $eventBus = new RecordingEventBus();
        $directory = new InMemoryActiveAdministratorDirectory([]);

        $this->expectException(UserNotFound::class);

        $this->makeUseCase($repository, $directory, $eventBus)->suspend(UserMother::DEFAULT_ID);
    }

    private function makeUseCase(
        InMemoryUserRepository $repository,
        InMemoryActiveAdministratorDirectory $directory,
        RecordingEventBus $eventBus,
    ): ChangeUserStatus {
        return new ChangeUserStatus($repository, $directory, $eventBus, $this->inlineTransactionManager());
    }

    private function inlineTransactionManager(): TransactionManager
    {
        $transactionManager = $this->createStub(TransactionManager::class);
        $transactionManager->method('transactional')->willReturnCallback(
            static fn (callable $operation): mixed => $operation(),
        );

        return $transactionManager;
    }
}
