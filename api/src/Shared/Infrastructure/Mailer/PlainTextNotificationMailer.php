<?php

declare(strict_types=1);

namespace Erpify\Shared\Infrastructure\Mailer;

use Erpify\Shared\Application\Mailer\NotificationMailer;
use Override;
use Symfony\Component\DependencyInjection\Attribute\AsAlias;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mime\Email;

/**
 * {@link NotificationMailer} using plain text plus an HTML body wrapped in a `pre` element (Symfony Mailer).
 */
#[AsAlias(NotificationMailer::class)]
final readonly class PlainTextNotificationMailer implements NotificationMailer
{
    public function __construct(
        private MailerInterface $mailer,
        #[Autowire('%env(MAILER_FROM)%')]
        private string $mailFrom,
    ) {
    }

    /**
     * @param array<string, mixed> $fields
     */
    #[Override]
    public function send(string $to, string $subject, array $fields, ?string $correlationLabel = null): void
    {
        $lines = [];

        if (null !== $correlationLabel && '' !== $correlationLabel) {
            $lines[] = 'Event: ' . $correlationLabel;
            $lines[] = '';
        }

        foreach ($fields as $key => $value) {
            $lines[] = \sprintf('%s: %s', $key, \is_scalar($value) ? $value : \json_encode($value));
        }

        $body = \implode("\n", $lines);

        $email = (new Email())
            ->from($this->mailFrom)
            ->to($to)
            ->subject($subject)
            ->text($body)
            ->html('<pre>' . \htmlspecialchars($body, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '</pre>')
        ;

        $this->mailer->send($email);
    }
}
