<?php

declare(strict_types=1);

namespace Erpify\Tests\DataFixtures\Purger;

use Doctrine\DBAL\Connection;
use Fidry\AliceDataFixtures\Persistence\PurgeMode;
use Fidry\AliceDataFixtures\Persistence\PurgerFactoryInterface;
use Fidry\AliceDataFixtures\Persistence\PurgerInterface;
use Override;
use Symfony\Component\DependencyInjection\Attribute\AsDecorator;

/**
 * Decorates the bundle's Doctrine purger factory so every purger it hands out also resets the
 * event-sourcing backbone ({@see EventBackbonePurger} explains why the ORM purge alone is not enough).
 *
 * The factory is the decoration point rather than the purger because the bundle never registers a
 * purger service: it registers a factory and calls `create()` per load, passing the purge mode. A
 * decorator on the factory is therefore the only seam that covers every purger actually used.
 */
#[AsDecorator(decorates: 'fidry_alice_data_fixtures.persistence.doctrine.purger.purger_factory')]
final readonly class EventBackbonePurgerFactory implements PurgerFactoryInterface
{
    public function __construct(
        private PurgerFactoryInterface $inner,
        private Connection $connection,
    ) {
    }

    #[Override]
    public function create(PurgeMode $mode, ?PurgerInterface $purger = null): PurgerInterface
    {
        return new EventBackbonePurger($this->inner->create($mode, $purger), $this->connection);
    }
}
