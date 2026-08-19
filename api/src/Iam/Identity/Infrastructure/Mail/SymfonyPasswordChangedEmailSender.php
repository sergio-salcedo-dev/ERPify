<?php

declare(strict_types=1);

namespace Erpify\Iam\Identity\Infrastructure\Mail;

use Erpify\Iam\Identity\Application\PasswordChangedEmailSender;
use Erpify\Shared\Mailer\Infrastructure\BulletproofEmailChrome;
use Erpify\Shared\Mailer\Infrastructure\DeliverableSecurityTransport;
use Erpify\Shared\Mailer\Infrastructure\RedactingMailer;
use Erpify\Shared\Mailer\Infrastructure\SecuritySenderAddress;
use Override;
use SensitiveParameter;
use Symfony\Component\DependencyInjection\Attribute\AsAlias;

/**
 * {@see PasswordChangedEmailSender} rendered through the shared {@see BulletproofEmailChrome}. Deliberately
 * NOT a {@see \Erpify\Shared\Mailer\Infrastructure\SecurityLinkMailer} caller: this notification has no link,
 * no CTA and no token, and bending the link-centric contract around an empty token would put the invitation
 * and reset emails at regression risk for a mail that shares only the chrome.
 *
 * The body is static and PII-free by design — no IP, timestamp or device: interpolating request context here
 * would drag it into the triggering domain event and its durable `event_store` row. "Wasn't you?" is answered
 * by the monitored security mailbox in the copy instead.
 *
 * It states the credential change and nothing else, because nothing else is certain at the moment of sending.
 * Not that open sessions were closed: the eager teardown is best-effort and swallows its failures, so the
 * app's own session rows can stay `ACTIVE` while the mail says otherwise. And not that the old password
 * stopped working: no constraint requires the new credential to differ from the old one, so a reset to the
 * same password would make that sentence false in the very run it is sent.
 */
#[AsAlias(PasswordChangedEmailSender::class)]
final readonly class SymfonyPasswordChangedEmailSender implements PasswordChangedEmailSender
{
    private const string SUBJECT = 'Your ERPify password has changed';

    public function __construct(
        private RedactingMailer $mailer,
        private SecuritySenderAddress $securityFrom,
        private BulletproofEmailChrome $chrome,
        private DeliverableSecurityTransport $transport,
    ) {
    }

    #[Override]
    public function send(#[SensitiveParameter] string $recipientEmail): void
    {
        $this->transport->ensureDeliverable();

        $from = $this->securityFrom->toString();

        $this->mailer->send(
            from: $from,
            recipientEmail: $recipientEmail,
            subject: self::SUBJECT,
            text: $this->textBody($from),
            html: $this->htmlBody($from),
        );
    }

    private function textBody(string $from): string
    {
        return \implode("\n", [
            'Your ERPify password has changed.',
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
            <p style="font-size:13px;line-height:1.5;color:#6b7280;margin:0;">
              If this was not you, contact {$safeFrom} immediately.
            </p>
            HTML);
    }
}
