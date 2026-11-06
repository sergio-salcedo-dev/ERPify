<?php

declare(strict_types=1);

namespace Erpify\Tests\Unit\Documents\Document\Application;

use ArrayObject;
use DateTimeImmutable;
use Erpify\Documents\Document\Domain\Entity\UploadIntent;
use Erpify\Documents\Document\Domain\Repository\UploadIntentRepository;
use Override;

/**
 * In-memory {@see UploadIntentRepository} for use-case tests; its clock is a field the test moves.
 *
 * @internal
 *
 * @SuppressWarnings("PHPMD.TooManyPublicMethods") one port, one double
 */
final class InMemoryUploadIntentRepository implements UploadIntentRepository
{
    /** @var array<string, UploadIntent> */
    public array $intents = [];

    /** @var list<string> */
    public array $anonymisedSubjects = [];

    /** @var ArrayObject<int, string>|null shared with the other doubles when a test asserts statement order */
    public ?ArrayObject $journal = null;

    public DateTimeImmutable $clock;

    public function __construct(private readonly int $actorsRewritten = 0)
    {
        $this->clock = new DateTimeImmutable('@2000000000');
    }

    #[Override]
    public function now(): DateTimeImmutable
    {
        return $this->clock;
    }

    #[Override]
    public function insertUnlessKeyTaken(UploadIntent $intent): bool
    {
        $keyHash = $intent->keyHash();

        if (null !== $keyHash && $this->findByKeyHash($keyHash) instanceof UploadIntent) {
            return false;
        }

        $this->intents[$intent->id()] = $intent;

        return true;
    }

    #[Override]
    public function findByKeyHash(string $keyHash): ?UploadIntent
    {
        foreach ($this->intents as $intent) {
            if ($intent->keyHash() === $keyHash) {
                return $intent;
            }
        }

        return null;
    }

    #[Override]
    public function lockForUpdate(string $id): ?UploadIntent
    {
        return $this->intents[$id] ?? null;
    }

    #[Override]
    public function save(UploadIntent $intent): void
    {
        $this->intents[$intent->id()] = $intent;
    }

    #[Override]
    public function delete(UploadIntent $intent): void
    {
        unset($this->intents[$intent->id()]);
    }

    #[Override]
    public function lockClaimSlots(string $actorId): void
    {
    }

    #[Override]
    public function lockDeclareSlot(string $actorId): void
    {
    }

    #[Override]
    public function countLiveStreamingFor(string $actorId, DateTimeImmutable $now): int
    {
        return 0;
    }

    #[Override]
    public function countLiveStreaming(DateTimeImmutable $now): int
    {
        return 0;
    }

    #[Override]
    public function countLiveDeclaredFor(string $actorId, DateTimeImmutable $now): int
    {
        return 0;
    }

    #[Override]
    public function reapCandidates(int $limit): array
    {
        return \array_keys($this->intents);
    }

    #[Override]
    public function deleteCommittedBefore(DateTimeImmutable $cutoff, int $limit): int
    {
        return 0;
    }

    #[Override]
    public function hasLiveIntentForScope(string $scopeId): bool
    {
        return false;
    }

    #[Override]
    public function anonymiseActor(string $subjectId): int
    {
        $this->anonymisedSubjects[] = $subjectId;
        $this->journal?->append('upload_intent');

        return $this->actorsRewritten;
    }
}
