<?php

declare(strict_types=1);

namespace Erpify\Tests\Unit\Shared\Architecture\Fixture;

use Erpify\Shared\Event\Domain\DomainEvent;
use Symfony\Component\Messenger\Attribute\AsMessage;

/**
 * Carries a routing attribute on an ABSTRACT PARENT — the idiomatic way to route a whole aggregate's events
 * at once instead of listing each. Symfony walks `class_parents()` to find it; PHP attribute inheritance does
 * not exist, so a concrete child reflects as carrying nothing.
 *
 * @internal
 */
#[AsMessage(transport: 'async')]
abstract class AbstractAsMessageCarrier extends DomainEvent
{
}
