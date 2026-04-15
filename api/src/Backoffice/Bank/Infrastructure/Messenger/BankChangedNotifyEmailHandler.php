<?php

declare(strict_types=1);

namespace Erpify\Backoffice\Bank\Infrastructure\Messenger;

use Erpify\Backoffice\Bank\Domain\Event\BankCreatedDomainEvent;
use Erpify\Backoffice\Bank\Domain\Event\BankUpdatedDomainEvent;
use Erpify\Shared\Application\Mailer\NotificationMailer;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

/**
 * Bank-specific routing: recipient and subjects. Formatting is delegated to {@see NotificationMailer}.
 */
final readonly class BankChangedNotifyEmailHandler
{
    public function __construct(
        private NotificationMailer $notificationMailer,
        #[Autowire('%env(DEFAULT_NOTIFICATION_EMAIL)%')]
        private string $notifyTo,
    ) {
    }

    #[AsMessageHandler]
    public function onBankCreated(BankCreatedDomainEvent $bankCreatedDomainEvent): void
    {
        $this->notificationMailer->send(
            $this->notifyTo,
            '[ERPify] Bank created',
            $bankCreatedDomainEvent->toPrimitives(),
            $bankCreatedDomainEvent::eventName(),
        );
    }

    #[AsMessageHandler]
    public function onBankUpdated(BankUpdatedDomainEvent $bankUpdatedDomainEvent): void
    {
        $this->notificationMailer->send(
            $this->notifyTo,
            '[ERPify] Bank updated',
            $bankUpdatedDomainEvent->toPrimitives(),
            $bankUpdatedDomainEvent::eventName(),
        );
    }
}
