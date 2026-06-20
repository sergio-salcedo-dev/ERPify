<?php

declare(strict_types=1);

namespace Erpify\Backoffice\Bank\Application\Projection;

use Erpify\Backoffice\Bank\Domain\Event\BankCreatedDomainEvent;
use Erpify\Backoffice\Bank\Domain\Event\BankDeletedDomainEvent;
use Erpify\Shared\Event\Application\Projector;
use Erpify\Shared\Event\Domain\DomainEvent;
use Override;

/**
 * The reference projector: `+1` on bank creation, `−1` on deletion, derived from the event log rather
 * than `COUNT(*)`. A `+1/−1` counter is not naturally idempotent — the checkpoint in
 * {@see \Erpify\Shared\Event\Application\ProjectionRunner} is what makes applying it exactly once safe,
 * in both live and rebuild (ADR D6/D7).
 */
final readonly class BankCountProjector implements Projector
{
    private const string NAME = 'bank_count';

    public function __construct(private BankCountReadModel $readModel)
    {
    }

    #[Override]
    public function name(): string
    {
        return self::NAME;
    }

    /**
     * @return list<string>
     */
    #[Override]
    public function subscribedTo(): array
    {
        return [
            BankCreatedDomainEvent::eventName(),
            BankDeletedDomainEvent::eventName(),
        ];
    }

    #[Override]
    public function project(DomainEvent $event): void
    {
        if ($event instanceof BankCreatedDomainEvent) {
            $this->readModel->increment();

            return;
        }

        if ($event instanceof BankDeletedDomainEvent) {
            $this->readModel->decrement();
        }
    }

    #[Override]
    public function reset(): void
    {
        $this->readModel->reset();
    }
}
