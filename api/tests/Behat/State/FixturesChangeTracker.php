<?php

declare(strict_types=1);

namespace Erpify\Tests\Behat\State;

/**
 * In-process flag flipped by {@see FixturesWriteListener} on every Doctrine
 * onFlush event. The fixtures context reads it to decide whether the next
 * feature needs a database restore. Starts as true so the first feature
 * always gets a clean state.
 */
final class FixturesChangeTracker
{
    private static bool $changed = true;

    public static function markChanged(): void
    {
        self::$changed = true;
    }

    public static function reset(): void
    {
        self::$changed = false;
    }

    public static function hasChanged(): bool
    {
        return self::$changed;
    }
}
