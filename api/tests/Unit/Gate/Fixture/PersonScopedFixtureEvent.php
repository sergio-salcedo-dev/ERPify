<?php

declare(strict_types=1);

namespace Erpify\Tests\Unit\Gate\Fixture;

/**
 * Marker implemented by {@see PersonAggregateFixtureEvent}, so the persistent-transport gate can be shown to
 * resolve a routing key that is an INTERFACE. Messenger folds `class_implements()` into the sender lookup, so
 * an interface key routes every implementor without naming one — a shape no real event carries today, which
 * is exactly why the gate needs a fixture to prove it is covered rather than merely unexercised.
 *
 * @internal
 */
interface PersonScopedFixtureEvent
{
}
