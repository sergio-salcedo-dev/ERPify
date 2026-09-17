<?php

declare(strict_types=1);

namespace Erpify\Tests\DataFixtures\Purger;

use Doctrine\DBAL\Connection;
use Erpify\Shared\Event\Application\Projector;
use Fidry\AliceDataFixtures\Persistence\PurgeMode;
use Fidry\AliceDataFixtures\Persistence\PurgerFactoryInterface;
use Fidry\AliceDataFixtures\Persistence\PurgerInterface;
use Override;
use Symfony\Component\DependencyInjection\Attribute\AsDecorator;
use Symfony\Component\DependencyInjection\Attribute\AutowireIterator;

/**
 * Decorates the bundle's Doctrine purger factory so every purger it hands out also resets the
 * event-sourcing backbone ({@see EventBackbonePurger} explains why the ORM purge alone is not enough).
 *
 * The bundle's service is both a `PurgerInterface` and a `PurgerFactoryInterface` (one class,
 * `Fidry\AliceDataFixtures\Bridge\Doctrine\Purger\Purger`, implementing the two), but `PurgerLoader`
 * reaches it only through `create()`, once per load, to apply the requested {@see PurgeMode}. Decorating
 * the factory side is therefore what covers every purger actually used. One consequence is worth
 * knowing: the bundle's deprecated `…persistence.purger.doctrine.orm_purger` alias points at the
 * decorated id, so it now resolves to this class — a factory, not a `PurgerInterface`. Nothing in `api/`
 * resolves that alias, and an alias is not type-checked at compile time, so this is recorded rather than
 * guarded.
 *
 * A no-purge mode is passed straight through. `PurgerLoader` never calls a factory in that mode today,
 * so the guard is unreachable — it is here because a decorator that truncates the event log while its
 * caller asked for no purge is wrong on its own terms, and being safe by the caller's grace is a
 * different thing from being safe by construction.
 */
#[AsDecorator(decorates: 'fidry_alice_data_fixtures.persistence.doctrine.purger.purger_factory')]
final readonly class EventBackbonePurgerFactory implements PurgerFactoryInterface
{
    /**
     * @param iterable<Projector> $projectors
     */
    public function __construct(
        private PurgerFactoryInterface $inner,
        private Connection $connection,
        #[AutowireIterator('erpify.projector')]
        private iterable $projectors,
    ) {
    }

    #[Override]
    public function create(PurgeMode $mode, ?PurgerInterface $purger = null): PurgerInterface
    {
        $inner = $this->inner->create($mode, $purger);

        if ($mode->getValue() === PurgeMode::createNoPurgeMode()->getValue()) {
            return $inner;
        }

        return new EventBackbonePurger($inner, $this->connection, $this->projectors);
    }
}
