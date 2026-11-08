<?php

declare(strict_types=1);

namespace Erpify\Tests\Functional\Documents\Document;

use DateTimeImmutable;
use Erpify\Documents\Document\Domain\Entity\UploadIntent;
use Erpify\Documents\Document\Domain\Repository\UploadIntentRepository;
use Override;
use RuntimeException;

/**
 * The real intent repository, except that locking a row fails as a lost connection or a deadlock would: what a
 * heartbeat meets when the database, not the lease, is what went wrong.
 *
 * @SuppressWarnings("PHPMD.TooManyPublicMethods") the repository port, delegated
 */
final readonly class FaultingLockIntentRepository implements UploadIntentRepository
{
    public function __construct(private UploadIntentRepository $inner, private RuntimeException $fault)
    {
    }

    #[Override]
    public function lockForUpdate(string $id): ?UploadIntent
    {
        throw $this->fault;
    }

    #[Override]
    public function now(): DateTimeImmutable
    {
        return $this->inner->now();
    }

    #[Override]
    public function insertUnlessKeyTaken(UploadIntent $intent): bool
    {
        return $this->inner->insertUnlessKeyTaken($intent);
    }

    #[Override]
    public function findByKeyHash(string $keyHash): ?UploadIntent
    {
        return $this->inner->findByKeyHash($keyHash);
    }

    #[Override]
    public function save(UploadIntent $intent): void
    {
        $this->inner->save($intent);
    }

    #[Override]
    public function delete(UploadIntent $intent): void
    {
        $this->inner->delete($intent);
    }

    #[Override]
    public function lockClaimSlots(string $actorId): void
    {
        $this->inner->lockClaimSlots($actorId);
    }

    #[Override]
    public function lockDeclareSlot(string $actorId): void
    {
        $this->inner->lockDeclareSlot($actorId);
    }

    #[Override]
    public function countLiveStreamingFor(string $actorId, DateTimeImmutable $now): int
    {
        return $this->inner->countLiveStreamingFor($actorId, $now);
    }

    #[Override]
    public function countLiveStreaming(DateTimeImmutable $now): int
    {
        return $this->inner->countLiveStreaming($now);
    }

    #[Override]
    public function countLiveDeclaredFor(string $actorId, DateTimeImmutable $now): int
    {
        return $this->inner->countLiveDeclaredFor($actorId, $now);
    }

    #[Override]
    public function reapCandidates(int $limit): array
    {
        return $this->inner->reapCandidates($limit);
    }

    #[Override]
    public function deleteCommittedBefore(DateTimeImmutable $cutoff, int $limit): int
    {
        return $this->inner->deleteCommittedBefore($cutoff, $limit);
    }

    #[Override]
    public function hasLiveIntentForScope(string $scopeId): bool
    {
        return $this->inner->hasLiveIntentForScope($scopeId);
    }

    #[Override]
    public function anonymiseActor(string $subjectId): int
    {
        return $this->inner->anonymiseActor($subjectId);
    }
}
