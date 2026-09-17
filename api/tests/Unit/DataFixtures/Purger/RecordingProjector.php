<?php

declare(strict_types=1);

namespace Erpify\Tests\Unit\DataFixtures\Purger;

use Erpify\Shared\Event\Application\Projector;
use Erpify\Shared\Event\Domain\DomainEvent;
use Override;

/** Records that its read model was reset, so the purge's fan-out over projectors is observable. */
final readonly class RecordingProjector implements Projector
{
    public function __construct(private string $name, private PurgeCallLog $log)
    {
    }

    #[Override]
    public function name(): string
    {
        return $this->name;
    }

    /**
     * @return list<string>
     */
    #[Override]
    public function subscribedTo(): array
    {
        return [];
    }

    #[Override]
    public function project(DomainEvent $event): void
    {
    }

    #[Override]
    public function reset(): void
    {
        $this->log->record('reset:' . $this->name);
    }
}
