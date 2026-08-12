<?php

declare(strict_types=1);

namespace Erpify\Shared\Event\Application;

use Erpify\Shared\Persistence\Application\TransactionManager;
use InvalidArgumentException;

/**
 * Drives projection catch-up over the event log by `sequence`, governed by a checkpoint. The same
 * mechanism serves both live (a reactor fires {@see catchUpAll()} after each delivered event) and
 * rebuild (CLI: {@see rebuild()} resets the read model and the checkpoint to 0 and replays).
 *
 * Each run is one transaction, owned at the orchestration boundary through the {@see TransactionManager}
 * port like the write-side use cases: the checkpoint row is locked `FOR UPDATE`, the read-model writes and
 * the checkpoint advance commit together, so a `sequence` is never applied twice nor out of order.
 *
 * **One transaction per projector, not one around the fan-out.** {@see catchUpAll()} delegates to
 * {@see catchUp()}, so each projector owns its own checkpoint boundary: a failure part-way leaves the
 * projectors that already advanced advanced, and the next run resumes from each checkpoint rather than
 * replaying the batch. Collapsing them into a single boundary would trade that resumability for an
 * all-or-nothing batch.
 *
 * **That resumability holds only when this runner is the outermost boundary** — the worker consuming the
 * transport. Reached synchronously instead, from a use case that published an event no transport routes,
 * every boundary here is a savepoint inside the caller's transaction: DBAL releases rather than commits, so
 * a rollback in the caller takes every checkpoint advanced under it back with it. Nothing detects that; it
 * is a property of where the runner was called from, not of what it does.
 */
final readonly class ProjectionRunner
{
    /**
     * Rows per stream page. Caught up in a loop until drained, so a backlog larger than this is still
     * fully applied within the run's single transaction.
     */
    private const int BATCH = 500;

    /**
     * @param iterable<Projector> $projectors
     */
    public function __construct(
        private EventStore $eventStore,
        private DomainEventDeserializer $deserializer,
        private ProjectionCheckpointStore $checkpointStore,
        private TransactionManager $transactionManager,
        private iterable $projectors,
    ) {
    }

    public function catchUp(string $name): void
    {
        $projector = $this->projector($name);

        $this->transactionManager->transactional(function () use ($projector, $name): void {
            $checkpoint = $this->checkpointStore->lockAndRead($name);
            $this->project($projector, $name, $checkpoint);
        });
    }

    /**
     * Catch up every registered projector, each in its own checkpoint transaction. The live trigger
     * calls this per delivered event regardless of subscription — an O(N) fan-out whose dominant cost
     * is the N checkpoint locks, not the (already `subscribedTo()`-filtered) stream scans. Kept
     * deliberately for its reconciliation guarantee; the subscription-filtered alternative is deferred
     * to decision D11 / trigger (k) in docs/adr/event-store-and-projections.md.
     */
    public function catchUpAll(): void
    {
        foreach ($this->projectors as $projector) {
            $this->catchUp($projector->name());
        }
    }

    public function rebuild(string $name): void
    {
        $projector = $this->projector($name);

        $this->transactionManager->transactional(function () use ($projector, $name): void {
            // checkpointStore->reset() locks/zeroes the checkpoint row inside this transaction;
            // projector->reset() clears the read model. Replaying from 0 in the same transaction
            // makes the rebuild atomic.
            $this->checkpointStore->reset($name);
            $projector->reset();
            $this->project($projector, $name, 0);
        });
    }

    public function rebuildAll(): void
    {
        foreach ($this->projectors as $projector) {
            $this->rebuild($projector->name());
        }
    }

    private function project(Projector $projector, string $name, int $from): void
    {
        $cursor = $from;

        do {
            $applied = 0;

            foreach ($this->eventStore->stream($cursor, $projector->subscribedTo(), self::BATCH) as $storedEvent) {
                $projector->project($this->deserializer->deserialize($storedEvent));
                $cursor = $storedEvent->sequence;
                ++$applied;
            }

            if ($cursor > $from) {
                $this->checkpointStore->advance($name, $cursor);
            }
        } while (self::BATCH === $applied);
    }

    private function projector(string $name): Projector
    {
        foreach ($this->projectors as $projector) {
            if ($projector->name() === $name) {
                return $projector;
            }
        }

        throw new InvalidArgumentException(\sprintf('No projector is registered under "%s".', $name));
    }
}
