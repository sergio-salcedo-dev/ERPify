<?php

declare(strict_types=1);

namespace Erpify\Tests\Behat\Doctrine;

use Doctrine\Bundle\DoctrineBundle\Attribute\AsDoctrineListener;
use Doctrine\ORM\Events;
use Erpify\Tests\Behat\State\FixturesChangeTracker;

/**
 * Marks the fixtures change tracker dirty whenever the ORM flushes. The
 * fixtures context uses that flag to skip the per-feature database restore
 * when no scenario actually wrote anything.
 */
#[AsDoctrineListener(event: Events::onFlush)]
final class FixturesWriteListener
{
    public function onFlush(): void
    {
        FixturesChangeTracker::markChanged();
    }
}
