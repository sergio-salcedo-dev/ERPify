<?php

declare(strict_types=1);

namespace Erpify\Shared\Event\Infrastructure\Serialization;

use Erpify\Shared\Event\Application\Upcaster;
use Override;
use Symfony\Component\DependencyInjection\Attribute\AsAlias;

/**
 * Identity {@link Upcaster}: the chain is empty, so every payload is read at the version it was stored
 * under. Registered as the autowired {@see Upcaster} via {@see AsAlias}.
 *
 * **Empty is not the same as "no event has ever been versioned."** The six `Iam.Invitation` events are at
 * `eventVersion` 2, and deliberately have no upcaster: what changed there is the meaning of the
 * `aggregate_id`, which this seam never sees. Leaving the chain empty is what makes a stored v1 row resolve
 * no class and fail loudly, rather than rehydrate under the wrong subject.
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
