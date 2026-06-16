<?php

declare(strict_types=1);

namespace Erpify\Tests\Behat\Support\Notification;

use Symfony\Component\Mime\Email;

/**
 * Process-static collector for notification emails captured off Symfony Mailer's `MessageEvent`
 * by {@see RecordingMailerSubscriber}. Read by {@see \Erpify\Tests\Behat\Context\NotificationContext};
 * cleared `@BeforeScenario`. Static so the context reads the captured emails directly, regardless of
 * which container instance Symfony Mailer resolves the subscriber from.
 */
final class RecordedEmails
{
    /** @var list<Email> */
    private static array $emails = [];

    public static function record(Email $email): void
    {
        self::$emails[] = $email;
    }

    /**
     * @return list<Email>
     */
    public static function all(): array
    {
        return self::$emails;
    }

    public static function reset(): void
    {
        self::$emails = [];
    }
}
