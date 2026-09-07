<?php

declare(strict_types=1);

namespace Erpify\Tests\Unit\DataFixtures\Purger;

use Fidry\AliceDataFixtures\Persistence\PurgeMode;
use Fidry\AliceDataFixtures\Persistence\PurgerFactoryInterface;
use Fidry\AliceDataFixtures\Persistence\PurgerInterface;
use Override;

/** Stands in for the bundle's factory: hands back the purger the test wants to observe. */
final readonly class FixedPurgerFactory implements PurgerFactoryInterface
{
    public function __construct(private PurgerInterface $purger)
    {
    }

    /**
     * @SuppressWarnings("PHPMD.UnusedFormalParameter") the signature is the interface's; the double
     *                                                  hands back a fixed purger whatever the mode
     */
    #[Override]
    public function create(PurgeMode $mode, ?PurgerInterface $purger = null): PurgerInterface
    {
        return $this->purger;
    }
}
