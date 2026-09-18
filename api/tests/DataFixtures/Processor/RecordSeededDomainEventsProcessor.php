<?php

declare(strict_types=1);

namespace Erpify\Tests\DataFixtures\Processor;

use Erpify\Shared\Event\Application\EventStore;
use Erpify\Shared\Kernel\Domain\Aggregate\AggregateRoot;
use Fidry\AliceDataFixtures\ProcessorInterface;
use Override;

/**
 * Keeps the seeded event log in step with the seeded rows.
 *
 * Fixtures build aggregates through their domain factories (`Bank::create(...)`), so the aggregate
 * records the very same {@see \Erpify\Shared\Event\Domain\DomainEvent} an application-created one
 * records. The loader then persists it with the ORM — which never calls `pullDomainEvents()`, so
 * every recorded event was discarded. A read model derived from the log therefore reported a total
 * of 0 over a table holding 31 seeded rows, and `event:projection:rebuild` could not repair it: a
 * rebuild replays the log, and the log was empty.
 *
 * **It appends to the store rather than publishing on the {@see \Erpify\Shared\Event\Domain\EventBus},
 * and that is the whole design.** Publishing would dispatch: outbox rows, `async` deliveries, realtime
 * broadcasts and every in-process handler would run at seed time, making fixture loading a
 * side-effecting application operation. Appending records history and nothing else. It also closes a
 * standing hazard rather than merely avoiding it — routing an event about a natural person to a
 * persistent transport is forbidden here, and a publishing seed would begin queuing person ids the
 * day someone routed one, silently. Nothing dispatched means no routing decision can ever reach this.
 * The store itself is safe ground for the same data: unlike the queue tables it has an erasure path
 * ({@see \Erpify\Shared\Event\Infrastructure\Persistence\DbalEventStoreSubjectAnonymiser}).
 *
 * Because nothing is dispatched, nothing triggers projection catch-up either
 * ({@see \Erpify\Shared\Event\Infrastructure\Messenger\RunProjectionsOnDomainEvent} is a message
 * handler, so it fires only on delivery). The seed pipeline replays explicitly afterwards —
 * `make db.load.fixtures` runs `event:projection:rebuild`. This processor on its own leaves every
 * projection untouched.
 *
 * Every aggregate is covered, not only the ones some projector reads today. Narrowing it to
 * `subscribedTo()` would reproduce this exact defect for the next projector written, and would leave
 * a seeded log that is partial in a way nothing announces.
 */
final readonly class RecordSeededDomainEventsProcessor implements ProcessorInterface
{
    public function __construct(private EventStore $eventStore)
    {
    }

    /**
     * Deliberately empty: the row does not exist yet. Appending here would put a creation in the log
     * ahead of the row it describes.
     *
     * @SuppressWarnings("PHPMD.UnusedFormalParameter") the signature is the interface's, not a choice
     */
    #[Override]
    public function preProcess(string $id, object $object): void
    {
    }

    /**
     * @SuppressWarnings("PHPMD.UnusedFormalParameter") `$id` is the fixture reference (`bank_of_america`);
     *                                                  the aggregate carries everything this needs
     */
    #[Override]
    public function postProcess(string $id, object $object): void
    {
        if (!$object instanceof AggregateRoot) {
            return;
        }

        foreach ($object->pullDomainEvents() as $domainEvent) {
            $this->eventStore->append($domainEvent);
        }
    }
}
