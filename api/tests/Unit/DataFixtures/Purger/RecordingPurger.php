<?php

declare(strict_types=1);

namespace Erpify\Tests\Unit\DataFixtures\Purger;

use Fidry\AliceDataFixtures\Persistence\PurgerInterface;
use Override;

/** Records that the decorated purge ran, and when relative to the backbone reset. */
final readonly class RecordingPurger implements PurgerInterface
{
    public function __construct(private PurgeCallLog $log)
    {
    }

    #[Override]
    public function purge(): void
    {
        $this->log->record('inner-purge');
    }
}
