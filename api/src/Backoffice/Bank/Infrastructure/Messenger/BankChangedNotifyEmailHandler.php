<?php

declare(strict_types=1);

namespace Erpify\Backoffice\Bank\Infrastructure\Messenger;

use Erpify\Backoffice\Bank\Domain\Event\BankCreatedDomainEvent;
use Erpify\Backoffice\Bank\Domain\Event\BankUpdatedDomainEvent;
use Erpify\Shared\Application\DomainEvent\DomainEventHandlerDeduplicator;
use Erpify\Shared\Application\Mailer\NotificationMailer;
use Erpify\Shared\Domain\Event\DomainEvent;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Throwable;

/**
 * Bank-specific routing: recipient and subjects. Formatting is delegated to {@see NotificationMailer}.
 */
final readonly class BankChangedNotifyEmailHandler
{
    public function __construct(
        private DomainEventHandlerDeduplicator $domainEventHandlerDeduplicator,
        private NotificationMailer $notificationMailer,
        #[Autowire('%env(DEFAULT_NOTIFICATION_EMAIL)%')]
        private string $notifyTo,
    ) {
    }

    #[AsMessageHandler]
    public function onBankCreated(BankCreatedDomainEvent $bankCreatedDomainEvent): void
    {
        $this->sendOnce($bankCreatedDomainEvent, '[ERPify] Bank created');
    }

    #[AsMessageHandler]
    public function onBankUpdated(BankUpdatedDomainEvent $bankUpdatedDomainEvent): void
    {
        $this->sendOnce($bankUpdatedDomainEvent, '[ERPify] Bank updated');
    }

    private function sendOnce(DomainEvent $domainEvent, string $subject): void
    {
        // why: Messenger delivery is at-least-once, and an email is not idempotent — a
        // redelivery after a successful send would mail the recipient twice. Claim the
        // (eventId, handler) pair first; a failed send releases the claim so the
        // transport's retry can attempt it again.
        if (!$this->domainEventHandlerDeduplicator->claim($domainEvent->eventId(), self::class)) {
            return;
        }

        try {
            $this->notificationMailer->send(
                $this->notifyTo,
                $subject,
                $domainEvent->toPrimitives(),
                $domainEvent::eventName(),
            );
        } catch (Throwable $throwable) {
            $this->domainEventHandlerDeduplicator->release($domainEvent->eventId(), self::class);

            throw $throwable;
        }
    }
}
