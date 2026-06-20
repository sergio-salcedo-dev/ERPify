<?php

declare(strict_types=1);

namespace Erpify\Shared\Event\Infrastructure\Serialization;

use Erpify\Shared\Event\Application\Upcaster;
use Override;
use Symfony\Component\DependencyInjection\Attribute\AsAlias;

/**
 * Identity {@link Upcaster}: the chain is empty today, so every payload is already at its current
 * version. A real upcaster is added the first time an event's `eventVersion()` is bumped. Registered
 * as the autowired {@see Upcaster} via {@see AsAlias}.
 */
#[AsAlias(Upcaster::class)]
final readonly class NullUpcaster implements Upcaster
{
    #[Override]
    public function upcast(string $eventName, int $fromVersion, array $payload): array
    {
        return $payload;
    }

    #[Override]
    public function targetVersion(string $eventName, int $fromVersion): int
    {
        return $fromVersion;
    }
}
