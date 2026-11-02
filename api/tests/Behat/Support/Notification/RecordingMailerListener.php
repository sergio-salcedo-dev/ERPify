<?php

declare(strict_types=1);

namespace Erpify\Tests\Behat\Support\Notification;

use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\Mailer\Event\MessageEvent;
use Symfony\Component\Mime\Email;

/**
 * Captures every {@see Email} handed to Symfony Mailer into {@see RecordedEmails}, exercising the
 * full real path (handler → {@see \Erpify\Shared\Mailer\Infrastructure\PlainTextNotificationMailer}
 * → `MailerInterface`) with `MAILER_DSN=null://null` so nothing leaves the process.
 *
 * The mailer is async (a `message_bus` is configured), so `MessageEvent` fires twice per email: once
 * when it is queued onto the bus, then again when the transport actually sends it. Recording only the
 * non-queued (real send) event counts each notification once. Only `Email` messages are kept, so
 * non-notification framework mail does not inflate the notification count.
 */
final class RecordingMailerListener
{
    #[AsEventListener(event: MessageEvent::class)]
    public function onMessage(MessageEvent $messageEvent): void
    {
        if ($messageEvent->isQueued()) {
            return;
        }

        $message = $messageEvent->getMessage();

        if ($message instanceof Email) {
            RecordedEmails::record($message);
        }
    }
}
