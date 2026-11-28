<?php

declare(strict_types=1);

namespace Erpify\Iam\Identity\Infrastructure\Mail;

use Erpify\Iam\Identity\Application\SecondFactorEnabledEmailSender;
use Erpify\Shared\Mailer\Infrastructure\BulletproofEmailChrome;
use Erpify\Shared\Mailer\Infrastructure\DeliverableSecurityTransport;
use Erpify\Shared\Mailer\Infrastructure\RedactingMailer;
use Erpify\Shared\Mailer\Infrastructure\SecuritySenderAddress;
use Override;
use SensitiveParameter;
use Symfony\Component\DependencyInjection\Attribute\AsAlias;

/**
 * {@see SecondFactorEnabledEmailSender} rendered through the shared {@see BulletproofEmailChrome}, the shape of
 * {@see SymfonyPasswordChangedEmailSender}: no link, no CTA, no token, and nothing that could finish or undo the
 * change from the mail itself.
 *
 * The body is static and PII-free by design — no IP, timestamp, device or authenticator name. It states the one
 * certain fact: two-step verification is now on. Not that the other devices were signed out: that teardown is
 * best-effort and swallows its failures, so the mail could otherwise claim what did not happen. "Wasn't you?" is
 * answered by the monitored security mailbox alone: whoever enabled it held the password, and once the second step
 * guards every sign-in path neither the recovery secret nor a reset gets past a factor the owner does not hold, so
 * pointing the owner at them would send them to a door that is shut.
 */
#[AsAlias(SecondFactorEnabledEmailSender::class)]
final readonly class SymfonySecondFactorEnabledEmailSender implements SecondFactorEnabledEmailSender
{
    private const string SUBJECT = 'Two-step verification is now on for your ERPify account';

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
            'Two-step verification is now on for your ERPify account.',
            '',
            'If this was not you, someone holding your password turned it on. Contact ' . $from . ' immediately.',
        ]);
    }

    private function htmlBody(string $from): string
    {
        $safeFrom = $this->chrome->escape($from);

        return $this->chrome->render(<<<HTML
            <p style="font-size:16px;line-height:1.5;margin:0 0 16px;">
              Two-step verification is now on for your <strong>ERPify</strong> account.
            </p>
            <p style="font-size:13px;line-height:1.5;color:#6b7280;margin:0;">
              If this was not you, someone holding your password turned it on. Contact {$safeFrom} immediately.
            </p>
            HTML);
    }
}
