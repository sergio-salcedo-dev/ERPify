<?php

declare(strict_types=1);

namespace Erpify\Tests\Functional\Documents\Document;

use Erpify\Documents\Document\Domain\Storage\DocumentStorage;
use Erpify\Documents\Document\Domain\Storage\DocumentStorageFailed;
use Erpify\Documents\Document\Domain\Storage\StorageArea;
use Override;

/**
 * The real document storage, with one failure scripted at a time: a delete or a promote that fails for one scope
 * (a poisoned item in a sweep's batch), a sink that refuses its writes past a byte count, or a walk that yields a
 * given list of scopes. Everything not scripted reaches the real storage.
 *
 * @SuppressWarnings("PHPMD.TooManyPublicMethods") the storage port, and the scripts
 */
final class ScriptedDocumentStorage implements DocumentStorage
{
    /** @var list<string> */
    public array $deleted = [];

    /** @var list<string> */
    public array $staged = [];

    /** @var list<string> */
    public array $synced = [];

    private bool $everyPromoteFails = false;

    private bool $everyDeleteFails = false;

    private bool $walkFails = false;

    private bool $promotionRaces = false;

    private bool $promotionLanded = false;

    /** @var list<string> */
    private array $failingDeletes = [];

    /** @var list<string> */
    private array $failingPromotes = [];

    private ?int $sinkFailsAfterBytes = null;

    /** @var list<string>|null */
    private ?array $walk = null;

    public function __construct(private readonly DocumentStorage $inner)
    {
    }

    public function failDeleteFor(string $scopeId): self
    {
        $this->failingDeletes[] = $scopeId;

        return $this;
    }

    public function failPromoteFor(string $scopeId): self
    {
        $this->failingPromotes[] = $scopeId;

        return $this;
    }

    public function failEveryDelete(): self
    {
        $this->everyDeleteFails = true;

        return $this;
    }

    /**
     * The object is promoted into the final area right after the first existence check anyone makes: a reader that
     * looks in staging first finds it in final, one that looks in final first finds it in neither.
     */
    public function promotionLandsAfterTheFirstCheck(): self
    {
        $this->promotionRaces = true;

        return $this;
    }

    public function failWalk(): self
    {
        $this->walkFails = true;

        return $this;
    }

    public function failEveryPromote(): self
    {
        $this->everyPromoteFails = true;

        return $this;
    }

    public function failSinkAfter(int $bytes): self
    {
        $this->sinkFailsAfterBytes = $bytes;

        return $this;
    }

    /**
     * @param list<string> $scopes
     */
    public function walkYields(array $scopes): self
    {
        $this->walk = $scopes;

        return $this;
    }

    #[Override]
    public function stage(string $scopeId, $stream): void
    {
        $this->inner->stage($scopeId, $stream);
    }

    #[Override]
    public function stageWith(string $scopeId, callable $writer): void
    {
        $this->staged[] = $scopeId;
        $limit = $this->sinkFailsAfterBytes;

        if (null === $limit) {
            $this->inner->stageWith($scopeId, $writer);

            return;
        }

        $this->inner->stageWith($scopeId, static function ($sink) use ($writer, $limit): void {
            $writer(RefusingSink::wrap($sink, $limit));
        });
    }

    #[Override]
    public function has(StorageArea $area, string $scopeId): bool
    {
        if ($this->promotionRaces) {
            $present = $this->promotionLanded && StorageArea::Final === $area;
            $this->promotionLanded = true;

            return $present;
        }

        return $this->inner->has($area, $scopeId);
    }

    #[Override]
    public function scopesOlderThan(StorageArea $area, int $olderThanSeconds): iterable
    {
        if ($this->walkFails) {
            throw DocumentStorageFailed::because($area, 'scripted walk failure');
        }

        return $this->walk ?? $this->inner->scopesOlderThan($area, $olderThanSeconds);
    }

    #[Override]
    public function promote(string $scopeId): void
    {
        if ($this->everyPromoteFails || \in_array($scopeId, $this->failingPromotes, true)) {
            throw DocumentStorageFailed::because(StorageArea::Final, 'scripted promote failure');
        }

        $this->inner->promote($scopeId);
    }

    #[Override]
    public function syncFinal(string $scopeId): void
    {
        $this->inner->syncFinal($scopeId);
        $this->synced[] = $scopeId;
    }

    #[Override]
    public function open(string $scopeId)
    {
        return $this->inner->open($scopeId);
    }

    #[Override]
    public function delete(StorageArea $area, string $scopeId): void
    {
        if ($this->everyDeleteFails || \in_array($scopeId, $this->failingDeletes, true)) {
            throw DocumentStorageFailed::because($area, 'scripted delete failure');
        }

        $this->inner->delete($area, $scopeId);
        $this->deleted[] = $scopeId;
    }
}
