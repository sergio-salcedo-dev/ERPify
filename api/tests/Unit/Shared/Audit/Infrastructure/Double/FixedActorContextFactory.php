<?php

declare(strict_types=1);

namespace Erpify\Tests\Unit\Shared\Audit\Infrastructure\Double;

use Erpify\Shared\Audit\Application\ActorContextFactory;
use Erpify\Shared\Audit\Domain\ActorContext;
use Override;

/**
 * {@see ActorContextFactory} returning a preset actor, so a test can assert the logger seals exactly
 * the actor the factory produced into the entry.
 *
 * @internal
 */
final readonly class FixedActorContextFactory implements ActorContextFactory
{
    public function __construct(private ActorContext $actor)
    {
    }

    #[Override]
    public function current(): ActorContext
    {
        return $this->actor;
    }
}
