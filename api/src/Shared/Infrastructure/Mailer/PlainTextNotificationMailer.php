<?php

declare(strict_types=1);

namespace Erpify\Shared\Infrastructure\Mailer;

use Erpify\Shared\Application\Mailer\NotificationMailer;
use Symfony\Component\DependencyInjection\Attribute\AsAlias;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mime\Email;

/**
 * {@link NotificationMailer} using plain text plus an HTML body wrapped in a `pre` element (Symfony Mailer).
 */
#[AsAlias(NotificationMailer::class)]
final class PlainTextNotificationMailer implements NotificationMailer
{
    public function __construct(
        private readonly MailerInterface $mailer,
        #[Autowire('%env(default:mailer_from_default:MAILER_FROM)%')]
        private readonly string $mailFrom,
    ) {
    }

    public function send(string $to, string $subject, array $fields, ?string $correlationLabel = null): void
    {
        $lines = [];
        if ($correlationLabel !== null && $correlationLabel !== '') {
            $lines[] = 'Event: '.$correlationLabel;
            $lines[] = '';
        }
        foreach ($fields as $key => $value) {
            $lines[] = sprintf('%s: %s', $key, $value);
        }

        $body = implode("\n", $lines);

        $email = (new Email())
            ->from($this->mailFrom)
            ->to($to)
            ->subject($subject)
            ->text($body)
            ->html('<pre>'.htmlspecialchars($body, \ENT_QUOTES | \ENT_SUBSTITUTE, 'UTF-8').'</pre>');

        $this->mailer->send($email);
    }
}
