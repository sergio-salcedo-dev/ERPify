<?php

declare(strict_types=1);

namespace Erpify\Tests\DataFixtures\Purger;

use Doctrine\DBAL\Connection;
use Erpify\Shared\Event\Application\Projector;
use Fidry\AliceDataFixtures\Persistence\PurgerInterface;
use Override;

/**
 * Extends the fixture purge to the event-sourcing backbone.
 *
 * Hautelook's purge is the ORM purger: it truncates mapped entity tables, and the backbone is raw DBAL
 * declared by migrations and `postGenerateSchema` listeners, so the purge that claims to reset the
 * database left the event log untouched. The consequence is not untidiness —
 * {@see \Erpify\Tests\DataFixtures\Processor\RecordSeededDomainEventsProcessor} appends the seeded
 * aggregates' recorded events on every load and each load mints fresh event ids (`DomainEvent::$eventId`
 * defaults to a new UUID, so `ON CONFLICT (event_id) DO NOTHING` cannot absorb a re-seed). Two runs of
 * `make db.load.fixtures` would leave 62 creation events over 31 rows and a projected total confidently
 * wrong in the opposite direction to the bug this exists to fix.
 *
 * **Read models are reset through {@see Projector::reset()}, never named here.** Naming them is the
 * shape that fails: the list would have to grow with every projector added, and the growth is invisible
 * until some total is silently wrong. Asking the projectors themselves is a mechanism, so a projector
 * registered tomorrow is covered by being registered.
 *
 * **`messenger_messages` is truncated, and it is the member that is easiest to get wrong by omission.**
 * It holds both queues (`async` and `failed` are one physical table). A bank event still sitting there
 * at seed time — a worker that was down, a dead letter inside its retention — is re-delivered
 * afterwards, and `PersistDomainEventMiddleware` appends it again. Before this class, that re-append hit
 * the surviving `event_store` row and `ON CONFLICT (event_id) DO NOTHING` made it a no-op; now the log
 * has just been emptied, so the same delivery inserts a phantom creation for a bank the purge destroyed
 * and the projected total exceeds the row count. Keeping the queue would preserve instructions about
 * rows that no longer exist. In dev that costs the diagnostic value of a `failed` row, which is the
 * right trade against a total nobody can tell is wrong.
 *
 * `audit_log` is deliberately absent: {@see \Erpify\Tests\Behat\Context\FixturesContext} clears it for
 * the acceptance lane's own reasons, and widening a dev-facing purge to the audit trail is a separate
 * decision with its own retention rules.
 */
final readonly class EventBackbonePurger implements PurgerInterface
{
    /**
     * The backbone tables proper. Read models are absent by design — see the class docblock.
     */
    private const string TRUNCATE = 'TRUNCATE event_store, projection_checkpoint, '
        . 'handled_domain_event, messenger_messages RESTART IDENTITY';

    /**
     * @param iterable<Projector> $projectors
     */
    public function __construct(
        private PurgerInterface $inner,
        private Connection $connection,
        private iterable $projectors,
    ) {
    }

    #[Override]
    public function purge(): void
    {
        $this->inner->purge();

        $this->connection->executeStatement(self::TRUNCATE);

        foreach ($this->projectors as $projector) {
            $projector->reset();
        }
    }
}
