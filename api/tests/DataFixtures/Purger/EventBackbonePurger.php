<?php

declare(strict_types=1);

namespace Erpify\Tests\DataFixtures\Purger;

use Doctrine\DBAL\Connection;
use Fidry\AliceDataFixtures\Persistence\PurgerInterface;
use Override;

/**
 * Extends the fixture purge to the event-sourcing backbone.
 *
 * Hautelook's purge is the ORM purger: it truncates mapped entity tables, and `event_store`,
 * `projection_checkpoint` and the read models are raw DBAL tables owned by migrations and a schema
 * listener. So the purge that claims to reset the database left the event log untouched, and the
 * consequence is not merely untidy — {@see \Erpify\Tests\DataFixtures\Processor\RecordSeededDomainEventsProcessor}
 * appends the seeded aggregates' recorded events on every load, and each load mints fresh event ids
 * (`DomainEvent::$eventId` defaults to a new UUID, so `ON CONFLICT (event_id) DO NOTHING` cannot
 * absorb a re-seed). Two runs of `make db.load.fixtures` would leave 62 creation events over 31 rows
 * and a projected total confidently wrong in the opposite direction to the bug being fixed.
 *
 * Truncating is the coherent reading of a purge rather than a convenience: the events describe rows
 * the same purge just destroyed, so keeping them preserves a history of nothing. `audit_log` is
 * deliberately NOT in this list — {@see \Erpify\Tests\Behat\Context\FixturesContext} clears it for the
 * acceptance lane's own reasons, and widening a dev-facing purge to the audit trail is a separate
 * decision with its own evidence-retention rules.
 */
final readonly class EventBackbonePurger implements PurgerInterface
{
    private const string TRUNCATE = 'TRUNCATE event_store, projection_checkpoint, bank_count, '
        . 'handled_domain_event RESTART IDENTITY';

    public function __construct(
        private PurgerInterface $inner,
        private Connection $connection,
    ) {
    }

    #[Override]
    public function purge(): void
    {
        $this->inner->purge();

        $this->connection->executeStatement(self::TRUNCATE);
    }
}
