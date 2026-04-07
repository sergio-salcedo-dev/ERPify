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
final class BankChangedNotifyEmailHandler
{
    public function __construct(
        private readonly NotificationMailer $notificationMailer,
        #[Autowire('%env(DEFAULT_NOTIFICATION_EMAIL)%')]
        private readonly string $notifyTo,
    ) {
    }

    #[AsMessageHandler]
    public function onBankCreated(BankCreatedDomainEvent $event): void
    {
        $this->notificationMailer->send(
            $this->notifyTo,
            '[ERPify] Bank created',
            $event->toPrimitives(),
            $event::eventName(),
        );
    }

    #[AsMessageHandler]
    public function onBankUpdated(BankUpdatedDomainEvent $event): void
    {
        $this->notificationMailer->send(
            $this->notifyTo,
            '[ERPify] Bank updated',
            $event->toPrimitives(),
            $event::eventName(),
        );
    }
}
