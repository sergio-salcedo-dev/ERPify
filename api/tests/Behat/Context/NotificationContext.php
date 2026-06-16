<?php

declare(strict_types=1);

namespace Erpify\Tests\Behat\Context;

use Behat\Hook\BeforeScenario;
use Behat\Step\Given;
use Behat\Step\Then;
use Erpify\Tests\Behat\Context\Abstraction\AbstractContext;
use Erpify\Tests\Behat\Support\Notification\RecordedEmails;
use Erpify\Tests\Behat\Support\RecordedItemSelector;
use Symfony\Component\Mime\Address;
use Symfony\Component\Mime\Email;

/**
 * Asserts the outbound-notification effect of consuming a bank event, via the real mailer path
 * (handler → {@see \Erpify\Shared\Infrastructure\Mailer\PlainTextNotificationMailer} → `MailerInterface`,
 * `MAILER_DSN=null://null`). Emails are captured off Symfony Mailer's `MessageEvent` by
 * {@see \Erpify\Tests\Behat\Support\Notification\RecordingMailerSubscriber} into {@see RecordedEmails}.
 *
 * Named for the notification *behaviour*, not the transport: email is the only channel today, but the
 * same context grows verbs as SMS / WhatsApp / Push / Slack arrive. ERPify notification emails are
 * plain text (key/value lines), so the steps assert recipient + subject + body-contains + count
 * rather than dot-path JSON — that structured vocabulary lives on the outbox event and Mercure update.
 */
final class NotificationContext extends AbstractContext
{
    private ?int $selectedIndex = null;

    #[BeforeScenario]
    #[Given('I reset the notification context')]
    public function reset(): void
    {
        RecordedEmails::reset();
        $this->selectedIndex = null;
    }

    #[Then(':number notification email was sent')]
    #[Then(':number notification emails were sent')]
    public function notificationEmailsWereSent(int $number): void
    {
        $count = \count(RecordedEmails::all());

        self::assertSame(
            $number,
            $count,
            \sprintf('%d notification emails were sent, but %d was expected', $count, $number),
        );
    }

    #[Then('I get the sent notification email number :value')]
    public function selectNotificationEmail(int $value): void
    {
        $this->selectedIndex = $value - 1;
    }

    #[Then('The notification email recipient should be :value')]
    public function recipientShouldBe(string $value): void
    {
        $recipients = \array_map(
            static fn (Address $address): string => $address->getAddress(),
            $this->selectedEmail()->getTo(),
        );

        self::assertContains(
            $value,
            $recipients,
            \sprintf('Recipient "%s" not found among: %s', $value, \implode(', ', $recipients)),
        );
    }

    #[Then('The notification email subject should be equal to :value')]
    public function subjectShouldBeEqualTo(string $value): void
    {
        self::assertSame($value, $this->selectedEmail()->getSubject());
    }

    #[Then('The notification email body should contain :text')]
    public function bodyShouldContain(string $text): void
    {
        self::assertStringContainsString($text, (string) $this->selectedEmail()->getTextBody());
    }

    private function selectedEmail(): Email
    {
        return (new RecordedItemSelector())->select(RecordedEmails::all(), $this->selectedIndex, 'notification email');
    }
}
