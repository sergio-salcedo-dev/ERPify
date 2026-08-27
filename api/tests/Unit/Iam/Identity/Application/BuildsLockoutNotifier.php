<?php

declare(strict_types=1);

namespace Erpify\Tests\Unit\Iam\Identity\Application;

use DateTimeImmutable;
use Erpify\Iam\Identity\Application\NotifyLockedIdentities;
use Erpify\Iam\Identity\Application\RecordLockoutNoticeAuditBestEffort;
use Erpify\Iam\Identity\Application\SendAccountLockedEmailBestEffort;
use Erpify\Iam\Identity\Domain\Entity\User;
use Erpify\Shared\Audit\Application\AuditLogger;
use Erpify\Shared\Clock\Domain\Clock;
use Erpify\Tests\Unit\Iam\Identity\Domain\Entity\Mother\UserMother;
use Erpify\Tests\Unit\Shared\Audit\Infrastructure\Double\RecordingAuditLogger;
use Psr\Log\NullLogger;

/**
 * Assembles a {@see NotifyLockedIdentities} around in-memory fakes, mirroring {@see BuildsLockoutRegistrar}.
 *
 * The assembly lives in a trait for the same reason as its sibling: the sweep takes several collaborators and
 * seeds locked aggregates, and counting all of them against a test class that merely wants one built is what
 * pushes it past the object-coupling threshold. What the class is left coupled to is what it *asserts* — the
 * audit collaborator defaults to a fresh {@see RecordingAuditLogger} wrapped in
 * {@see RecordLockoutNoticeAuditBestEffort} so every existing caller stays untouched, and a test that cares
 * about the audit projection passes its own {@see AuditLogger} double explicitly.
 *
 * @internal
 */
trait BuildsLockoutNotifier
{
    private function sweep(
        InMemoryUserRepository $repository,
        RecordingAccountLockedEmailSender $sender,
        Clock $clock,
        string ...$lockedIds,
    ): void {
        $this->notifier($repository, $sender, $clock, ...$lockedIds)->notifyLockedOwners();
    }

    private function notifier(
        InMemoryUserRepository $repository,
        RecordingAccountLockedEmailSender $sender,
        Clock $clock,
        string ...$lockedIds,
    ): NotifyLockedIdentities {
        return $this->notifierWith(
            $repository,
            $sender,
            $clock,
            new InMemoryLockedIdentityDirectory(\array_values($lockedIds)),
        );
    }

    private function notifierWith(
        InMemoryUserRepository $repository,
        RecordingAccountLockedEmailSender $sender,
        Clock $clock,
        InMemoryLockedIdentityDirectory $directory,
        ?AuditLogger $auditLogger = null,
    ): NotifyLockedIdentities {
        return new NotifyLockedIdentities(
            $directory,
            $repository,
            new SendAccountLockedEmailBestEffort($sender, new NullLogger()),
            $clock,
            new RecordLockoutNoticeAuditBestEffort($auditLogger ?? new RecordingAuditLogger(), new NullLogger()),
        );
    }

    private function repositoryOf(User ...$users): InMemoryUserRepository
    {
        $repository = new InMemoryUserRepository();

        foreach ($users as $user) {
            $repository->seeded[] = $user;
        }

        return $repository;
    }

    /** An identity locked at `$now`, with the lockout event pulled so only the sweep's own effects remain. */
    private function lockedAt(DateTimeImmutable $now, string $id): User
    {
        $user = UserMother::create($id, $this->emailFor($id));

        for ($attempt = 0; $attempt < User::MAX_FAILED_ATTEMPTS; ++$attempt) {
            $user->recordFailedAttempt($now);
        }

        $user->pullDomainEvents();

        return $user;
    }

    private function emailFor(string $id): string
    {
        return \sprintf('locked-%s@erpify.test', \substr($id, -1));
    }

    /**
     * @return list<string>
     */
    private function emailsOf(string ...$ids): array
    {
        return \array_map($this->emailFor(...), \array_values($ids));
    }

    /**
     * @param list<User> $users
     *
     * @return list<?string>
     */
    private function idsOf(array $users): array
    {
        return \array_map(static fn (User $user): ?string => $user->getId(), $users);
    }
}
