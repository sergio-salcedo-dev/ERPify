<?php

declare(strict_types=1);

namespace Erpify\Shared\Event\Infrastructure\Messenger;

use DateTimeImmutable;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Types\Types;
use Erpify\Shared\Event\Application\HandledDomainEventPruner;
use Override;
use Symfony\Component\DependencyInjection\Attribute\AsAlias;

/**
 * {@link HandledDomainEventPruner} backed by the `handled_domain_event` table.
 */
#[AsAlias(HandledDomainEventPruner::class)]
final readonly class DbalHandledDomainEventPruner implements HandledDomainEventPruner
{
    public function __construct(
        private Connection $connection,
    ) {
    }

    #[Override]
    public function pruneClaimedBefore(DateTimeImmutable $threshold): int
    {
        return (int) $this->connection->executeStatement(
            'DELETE FROM handled_domain_event WHERE claimed_at < :threshold',
            ['threshold' => $threshold],
            ['threshold' => Types::DATETIME_IMMUTABLE],
        );
    }
}
