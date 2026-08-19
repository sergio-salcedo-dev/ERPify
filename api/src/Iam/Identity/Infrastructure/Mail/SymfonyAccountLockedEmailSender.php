<?php

declare(strict_types=1);

namespace Erpify\Iam\Identity\Infrastructure\Mail;

use Erpify\Iam\Identity\Application\AccountLockedEmailSender;
use Erpify\Shared\Mailer\Infrastructure\BulletproofEmailChrome;
use Erpify\Shared\Mailer\Infrastructure\DeliverableSecurityTransport;
use Erpify\Shared\Mailer\Infrastructure\SecuritySenderAddress;
use Override;
use SensitiveParameter;
use Symfony\Component\DependencyInjection\Attribute\AsAlias;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mime\Email;

/**
 * {@see AccountLockedEmailSender} rendered through the shared {@see BulletproofEmailChrome}, mirroring
 * {@see SymfonyPasswordChangedEmailSender} — the repo's other linkless security notice — rather than
 * {@see \Erpify\Shared\Mailer\Infrastructure\SecurityLinkMailer}, whose contract requires a token and a CTA
 * path this mail must not have.
 *
 * **No link of any kind, and that is the invariant, not the styling.** The address this arrives at is the
 * one an attacker uses to trip the lockout, so anything exercisable here would be a recovery lever delivered
 * straight into the compromised namespace.
 *
 * The body carries no IP, device, timestamp or attempt count. None of them serves the owner's next action,
 * and each would widen what a reader of the mailbox learns — including the attacker, if the mailbox is what
 * they hold.
 *
 * The order of the guidance is the substance: **revoke other sessions first, change the password after.** A
 * live session survives the lockout (`SessionAdmissionGate` weighs only session status and expiry), so an
 * owner already signed in somewhere still holds the one lever no budget gates; rotating first would spend
 * effort on the door while the intruder is inside.
 */
#[AsAlias(AccountLockedEmailSender::class)]
final readonly class SymfonyAccountLockedEmailSender implements AccountLockedEmailSender
{
    private const string SUBJECT = 'Your ERPify account has been temporarily locked';

    public function __construct(
        private MailerInterface $mailer,
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
            'Your ERPify account has been temporarily locked after repeated failed sign-in attempts.',
            'It unlocks again on its own after a short period.',
            '',
            'If this was not you, and you are still signed in somewhere you trust:',
            'review your active sessions and revoke the others first, then change your password.',
            '',
            'Contact ' . $from . ' if you need help.',
        ]);
    }

    private function htmlBody(string $from): string
    {
        $safeFrom = $this->chrome->escape($from);

        return $this->chrome->render(<<<HTML
            <p style="font-size:16px;line-height:1.5;margin:0 0 16px;">
              Your <strong>ERPify</strong> account has been temporarily locked after repeated failed
              sign-in attempts. It unlocks again on its own after a short period.
            </p>
            <p style="font-size:14px;line-height:1.5;margin:0 0 16px;">
              If this was not you, and you are still signed in somewhere you trust: review your active
              sessions and <strong>revoke the others first</strong>, then change your password.
            </p>
            <p style="font-size:13px;line-height:1.5;color:#6b7280;margin:0;">
              Contact {$safeFrom} if you need help.
            </p>
            HTML);
    }
}
