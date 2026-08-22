<?php

declare(strict_types=1);

namespace Erpify\Tests\Unit\Gate\Fixture;

/**
 * Carries a routing attribute on an INTERFACE, via a subclass of `AsMessage`. PHP does not inherit
 * attributes, so an implementor reflects as carrying none — yet Symfony walks `class_implements()` and routes
 * it. Two blind spots in one shape, which is why the fixture combines them.
 *
 * @internal
 */
#[PersistedRoutingFixtureAttribute(transport: 'failed')]
interface AsMessageCarrierContract
{
}
