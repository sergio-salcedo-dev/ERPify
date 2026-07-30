<?php

declare(strict_types=1);

namespace Erpify\Iam\Identity\Infrastructure\Mail;

use Erpify\Iam\Identity\Application\PasswordChangedEmailSender;
use Erpify\Shared\Mailer\Infrastructure\BulletproofEmailChrome;
use Erpify\Shared\Mailer\Infrastructure\SecuritySenderAddress;
use Override;
use Symfony\Component\DependencyInjection\Attribute\AsAlias;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mime\Email;

/**
 * {@see PasswordChangedEmailSender} rendered through the shared {@see BulletproofEmailChrome}. Deliberately
 * NOT a {@see \Erpify\Shared\Mailer\Infrastructure\SecurityLinkMailer} caller: this notification has no link,
 * no CTA and no token, and bending the link-centric contract around an empty token would put the invitation
 * and reset emails at regression risk for a mail that shares only the chrome.
 *
 * The body is static and PII-free by design — no IP, timestamp or device: interpolating request context here
 * would drag it into the triggering domain event and its durable `event_store` row. "Wasn't you?" is answered
 * by the monitored security mailbox in the copy instead.
 */
#[AsAlias(PasswordChangedEmailSender::class)]
final readonly class SymfonyPasswordChangedEmailSender implements PasswordChangedEmailSender
{
    private const string SUBJECT = 'Your ERPify password has changed';

    public function __construct(
        private MailerInterface $mailer,
        private SecuritySenderAddress $securityFrom,
        private BulletproofEmailChrome $chrome,
    ) {
    }

    #[Override]
    public function send(string $recipientEmail): void
    {
        $from = $this->securityFrom->toString();

        $email = (new Email())
            ->from($from)
            ->to($recipientEmail)
            ->subject(self::SUBJECT)
            ->text($this->textBody($from))
            ->html($this->htmlBody($from))
        ;

        $this->mailer->send($email);
    }

    private function textBody(string $from): string
    {
        return \implode("\n", [
            'Your ERPify password has changed.',
            'Your previous password no longer works.',
            '',
            'If this was not you, contact ' . $from . ' immediately.',
        ]);
    }

    private function htmlBody(string $from): string
    {
        $safeFrom = $this->chrome->escape($from);

        return $this->chrome->render(<<<HTML
            <p style="font-size:16px;line-height:1.5;margin:0 0 16px;">
              Your <strong>ERPify</strong> password has changed.
            </p>
            <p style="font-size:16px;line-height:1.5;margin:0 0 24px;">
              Your previous password no longer works.
            </p>
            <p style="font-size:13px;line-height:1.5;color:#6b7280;margin:0;">
              If this was not you, contact {$safeFrom} immediately.
            </p>
            HTML);
    }
}
