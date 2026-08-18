<?php

declare(strict_types=1);

namespace Erpify\Backoffice\Bank\Infrastructure\Messenger;

use Erpify\Backoffice\Bank\Domain\Event\BankCreatedDomainEvent;
use Erpify\Backoffice\Bank\Domain\Event\BankUpdatedDomainEvent;
use Erpify\Shared\Event\Application\DomainEventHandlerDeduplicator;
use Erpify\Shared\Event\Domain\DomainEvent;
use Erpify\Shared\Mailer\Application\NotificationMailer;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Throwable;

/**
 * Bank-specific routing: recipient and subjects. Formatting is delegated to {@see NotificationMailer}.
 */
final readonly class SendEmailOnBankChanged
{
    public function __construct(
        private DomainEventHandlerDeduplicator $domainEventHandlerDeduplicator,
        private NotificationMailer $notificationMailer,
        #[Autowire(service: 'monolog.logger.observability')]
        private LoggerInterface $logger,
        #[Autowire(env: 'DEFAULT_NOTIFICATION_EMAIL')]
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
        // transport's retry can attempt it again. Residual: if send() dispatched the mail
        // and then threw, the release re-opens the claim and the retry re-sends — accepted,
        // because for a notification mail guaranteed delivery beats exactly-once.
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
            $this->releaseClaim($domainEvent->eventId());

            throw $throwable;
        }
    }

    private function releaseClaim(string $eventId): void
    {
        try {
            $this->domainEventHandlerDeduplicator->release($eventId, self::class);
        } catch (Throwable $throwable) {
            // why: the send failure is the real error the transport must see; a release
            // failure on top must not mask it. Log it (the claim then lingers, suppressing
            // the retry — the at-most-once sliver) and let the original exception propagate.
            $this->logger->warning('Failed to release domain-event handler claim after a send failure.', [
                'eventId' => $eventId,
                'handler' => self::class,
                'exception' => $throwable,
            ]);
        }
    }
}
