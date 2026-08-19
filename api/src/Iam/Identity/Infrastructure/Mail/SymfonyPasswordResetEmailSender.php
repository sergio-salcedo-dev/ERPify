<?php

declare(strict_types=1);

namespace Erpify\Iam\Identity\Infrastructure\Mail;

use Erpify\Iam\Identity\Application\PasswordResetEmailSender;
use Erpify\Shared\Mailer\Infrastructure\SecurityLinkEmailContent;
use Erpify\Shared\Mailer\Infrastructure\SecurityLinkMailer;
use Override;
use SensitiveParameter;
use Symfony\Component\DependencyInjection\Attribute\AsAlias;

/**
 * {@see PasswordResetEmailSender} backed by the shared {@see SecurityLinkMailer}: only the reset copy and the
 * `reset-password` route live here — the bulletproof-link HTML/text scaffold (the same one the invitation email
 * uses) is rendered once in the shared mailer. The raw token rides only in the emailed URL, never in a log or
 * event.
 */
#[AsAlias(PasswordResetEmailSender::class)]
final readonly class SymfonyPasswordResetEmailSender implements PasswordResetEmailSender
{
    public function __construct(private SecurityLinkMailer $mailer)
    {
    }

    #[Override]
    public function send(#[SensitiveParameter] string $recipientEmail, #[SensitiveParameter] string $resetToken): void
    {
        $this->mailer->send($this->content(), $recipientEmail, $resetToken);
    }

    private function content(): SecurityLinkEmailContent
    {
        return new SecurityLinkEmailContent(
            path: '/reset-password',
            subject: 'Reset your ERPify password',
            textLead: [
                'You requested to reset your ERPify password.',
                '',
                'Open this link to set a new password (it expires in one hour):',
            ],
            textTrailer: [
                'When you reset it, we will sign out all your open sessions for security.',
                'If you did not request this change, you can ignore this message: your password will not change.',
            ],
            htmlLead: 'You requested to reset your <strong>ERPify</strong> password.',
            htmlDetail: 'Set a new password. The link expires in one hour and we will sign out all '
                . 'your open sessions for security.',
            ctaLabel: 'Reset password',
            htmlFootnote: 'If you did not request this change, you can ignore this message: '
                . 'your password will not change.',
        );
    }
}
