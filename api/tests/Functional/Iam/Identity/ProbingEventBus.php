<?php

declare(strict_types=1);

namespace Erpify\Tests\Functional\Iam\Identity;

use Closure;
use Erpify\Shared\Event\Domain\DomainEvent;
use Erpify\Shared\Event\Domain\EventBus;
use Override;

/**
 * The real {@see EventBus}, with a hook that runs before each publication — the one instant a collaborator
 * that publishes as its LAST act inside a transaction exposes to an observer: its locks taken, its writes
 * issued, the transaction not yet committed. It forwards rather than replacing, so what is published is what
 * production publishes.
 *
 * @internal
 */
final readonly class ProbingEventBus implements EventBus
{
    public function __construct(
        private EventBus $inner,
        private Closure $beforePublish,
    ) {
    }

    #[Override]
    public function publish(DomainEvent ...$events): void
    {
        ($this->beforePublish)();

        $this->inner->publish(...$events);
    }
}
