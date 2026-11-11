<?php

declare(strict_types=1);

namespace Erpify\Tests\Functional\Shared\Event\Fixtures;

/**
 * A message routed to `async` only by {@see AsyncProbeRig}, which serialises it into a real
 * `messenger_messages` row. It is not a DomainEvent, so a case needs no `event_store` and asserts the
 * transport alone.
 *
 * @internal
 */
final readonly class AsyncProbeMessage
{
}
