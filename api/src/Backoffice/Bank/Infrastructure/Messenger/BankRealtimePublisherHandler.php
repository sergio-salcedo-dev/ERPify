<?php

declare(strict_types=1);

namespace Erpify\Backoffice\Bank\Infrastructure\Messenger;

use Erpify\Backoffice\Bank\Domain\Event\BankCreatedDomainEvent;
use Erpify\Backoffice\Bank\Domain\Event\BankDeletedDomainEvent;
use Erpify\Backoffice\Bank\Domain\Event\BankUpdatedDomainEvent;
use Erpify\Backoffice\Bank\Domain\MercureBankTopic;
use Erpify\Backoffice\BankAccount\Domain\Repository\BankAccountCounter;
use JsonException;
use Symfony\Component\Mercure\HubInterface;
use Symfony\Component\Mercure\Update;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

/**
 * Publishes bank lifecycle events to the Mercure hub on private topics so other
 * connected back-office clients update their list/detail views in real time.
 *
 * Duplicate-safe: at-least-once delivery may re-publish an Update, but clients
 * reconcile by id (re-applying an update or a delete is a no-op once in sync).
 * Only the public {@see BankCreatedDomainEvent::toPrimitives()} shape leaves
 * here — logo / stored-object metadata is intentionally dropped.
 */
final readonly class BankRealtimePublisherHandler
{
    public function __construct(
        private HubInterface $hub,
        private BankAccountCounter $accountCountsByBank,
    ) {
    }

    /**
     * @throws JsonException
     */
    #[AsMessageHandler]
    public function onBankCreated(BankCreatedDomainEvent $event): void
    {
        $this->publish(
            [MercureBankTopic::COLLECTION],
            ['type' => 'bank.created', 'bank' => $this->bankPayload($event->toPrimitives())],
        );
    }

    /**
     * @throws JsonException
     */
    #[AsMessageHandler]
    public function onBankUpdated(BankUpdatedDomainEvent $event): void
    {
        $bankId = $event->aggregateId();

        $this->publish(
            [MercureBankTopic::COLLECTION, MercureBankTopic::forBank($bankId)],
            ['type' => 'bank.updated', 'bank' => $this->bankPayload($event->toPrimitives())],
        );
    }

    /**
     * @throws JsonException
     */
    #[AsMessageHandler]
    public function onBankDeleted(BankDeletedDomainEvent $event): void
    {
        $bankId = $event->aggregateId();

        $this->publish(
            [MercureBankTopic::COLLECTION, MercureBankTopic::forBank($bankId)],
            ['type' => 'bank.deleted', 'id' => $bankId],
        );
    }

    /**
     * @param list<string>         $topics
     * @param array<string, mixed> $data
     *
     * @throws JsonException
     */
    private function publish(array $topics, array $data): void
    {
        $this->hub->publish(new Update(
            $topics,
            \json_encode($data, JSON_THROW_ON_ERROR),
            true,
        ));
    }

    /**
     * Maps domain-event primitives to the PWA `BankPrimitives` shape. Safe `??`
     * access keeps the contract resilient if the event payload ever changes.
     * `accountCount` is resolved at publish time so the PWA never receives `undefined`.
     *
     * @param array<string, string|null> $primitives
     *
     * @return array<string, string|int|null>
     */
    private function bankPayload(array $primitives): array
    {
        $bankId = $primitives['bankId'] ?? null;
        $count = null !== $bankId
            ? ($this->accountCountsByBank->countsByBankIds([$bankId])[$bankId] ?? 0)
            : 0;

        return [
            'id' => $bankId,
            'name' => $primitives['name'] ?? null,
            'shortName' => $primitives['shortName'] ?? null,
            'createdAt' => $primitives['createdAt'] ?? null,
            'updatedAt' => $primitives['updatedAt'] ?? null,
            'accountCount' => $count,
        ];
    }
}
