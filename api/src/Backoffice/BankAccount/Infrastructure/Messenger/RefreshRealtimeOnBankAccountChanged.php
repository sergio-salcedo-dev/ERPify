<?php

declare(strict_types=1);

namespace Erpify\Backoffice\BankAccount\Infrastructure\Messenger;

use Erpify\Backoffice\BankAccount\Domain\Event\BankAccountCreatedDomainEvent;
use Erpify\Backoffice\BankAccount\Domain\Event\BankAccountDeletedDomainEvent;
use Erpify\Backoffice\BankAccount\Domain\Event\BankAccountStatusChangedDomainEvent;
use Erpify\Backoffice\BankAccount\Domain\Event\BankAccountUpdatedDomainEvent;
use Erpify\Backoffice\BankAccount\Domain\MercureBankAccountTopic;
use JsonException;
use Symfony\Component\Mercure\HubInterface;
use Symfony\Component\Mercure\Update;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

/**
 * Publishes bank-account lifecycle events to the Mercure hub on private topics so other connected
 * back-office clients refetch their list/detail views in real time.
 *
 * The broadcast is deliberately minimal and PII-free — `{type, id, bankId}` only, never the IBAN /
 * holder name / BIC / alias. The PWA reacts by refetching through the authenticable read; it never
 * consumes account data from the payload. Duplicate-safe: at-least-once delivery may re-publish an
 * Update, but clients reconcile by refetching (a replay is a no-op once in sync), so this handler is
 * intentionally not deduplicated.
 */
final readonly class RefreshRealtimeOnBankAccountChanged
{
    public function __construct(
        private HubInterface $hub,
    ) {
    }

    /**
     * @throws JsonException
     */
    #[AsMessageHandler]
    public function onBankAccountCreated(BankAccountCreatedDomainEvent $event): void
    {
        $this->publish('bank_account.created', $event->aggregateId(), $event->bankId());
    }

    /**
     * @throws JsonException
     */
    #[AsMessageHandler]
    public function onBankAccountUpdated(BankAccountUpdatedDomainEvent $event): void
    {
        $this->publish('bank_account.updated', $event->aggregateId(), $event->bankId());
    }

    /**
     * @throws JsonException
     */
    #[AsMessageHandler]
    public function onBankAccountDeleted(BankAccountDeletedDomainEvent $event): void
    {
        $this->publish('bank_account.deleted', $event->aggregateId(), $event->bankId());
    }

    /**
     * A lifecycle transition signals the same "refetch" to the UI as a descriptive edit, so it reuses
     * the `bank_account.updated` realtime type — the precise from/to fact lives in the event store, not
     * the broadcast.
     *
     * @throws JsonException
     */
    #[AsMessageHandler]
    public function onBankAccountStatusChanged(BankAccountStatusChangedDomainEvent $event): void
    {
        $this->publish('bank_account.updated', $event->aggregateId(), $event->bankId());
    }

    /**
     * @throws JsonException
     */
    private function publish(string $type, string $accountId, string $bankId): void
    {
        $this->hub->publish(new Update(
            [MercureBankAccountTopic::forBank($bankId), MercureBankAccountTopic::forAccount($accountId)],
            \json_encode(['type' => $type, 'id' => $accountId, 'bankId' => $bankId], JSON_THROW_ON_ERROR),
            true,
        ));
    }
}
