<?php

declare(strict_types=1);

namespace Erpify\Shared\Event\Infrastructure\Messenger;

use Doctrine\DBAL\Connection;
use Erpify\Shared\Event\Application\DomainEventHandlerDeduplicator;
use Override;
use Symfony\Component\DependencyInjection\Attribute\AsAlias;

/**
 * {@link DomainEventHandlerDeduplicator} backed by the `handled_domain_event` table.
 *
 * Plain DBAL on purpose: `INSERT … ON CONFLICT DO NOTHING` makes the claim a single atomic
 * statement (no check-then-act window between concurrent workers), and a conflict never
 * closes the ORM entity manager the way a flush-time unique violation would.
 */
#[AsAlias(DomainEventHandlerDeduplicator::class)]
final readonly class DbalDomainEventHandlerDeduplicator implements DomainEventHandlerDeduplicator
{
    public function __construct(
        private Connection $connection,
    ) {
    }

    #[Override]
    public function claim(string $eventId, string $handlerKey): bool
    {
        $affected = $this->connection->executeStatement(
            'INSERT INTO handled_domain_event (event_id, handler, claimed_at) VALUES (:event_id, :handler, NOW()) '
            . 'ON CONFLICT (event_id, handler) DO NOTHING',
            ['event_id' => $eventId, 'handler' => $handlerKey],
        );

        return 1 === (int) $affected;
    }

    #[Override]
    public function release(string $eventId, string $handlerKey): void
    {
        $this->connection->executeStatement(
            'DELETE FROM handled_domain_event WHERE event_id = :event_id AND handler = :handler',
            ['event_id' => $eventId, 'handler' => $handlerKey],
        );
    }
}
