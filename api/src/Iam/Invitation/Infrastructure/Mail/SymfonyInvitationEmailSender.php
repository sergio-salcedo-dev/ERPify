<?php

declare(strict_types=1);

namespace Erpify\Iam\Invitation\Infrastructure\Mail;

use Erpify\Iam\Invitation\Application\InvitationEmailSender;
use Erpify\Shared\Mailer\Infrastructure\SecurityLinkEmailContent;
use Erpify\Shared\Mailer\Infrastructure\SecurityLinkMailer;
use Override;
use SensitiveParameter;
use Symfony\Component\DependencyInjection\Attribute\AsAlias;

/**
 * {@see InvitationEmailSender} backed by the shared {@see SecurityLinkMailer}: only the invitation copy and the
 * `accept-invitation` route live here — the bulletproof-link HTML/text scaffold (the same one the password-reset
 * email uses) is rendered once in the shared mailer. The raw token rides only in the emailed URL, never in a log
 * or event.
 */
#[AsAlias(InvitationEmailSender::class)]
final readonly class SymfonyInvitationEmailSender implements InvitationEmailSender
{
    public function __construct(private SecurityLinkMailer $mailer)
    {
    }

    #[Override]
    public function send(#[SensitiveParameter] string $recipientEmail, #[SensitiveParameter] string $acceptToken): void
    {
        $this->mailer->send($this->content(), $recipientEmail, $acceptToken);
    }

    private function content(): SecurityLinkEmailContent
    {
        return new SecurityLinkEmailContent(
            path: '/accept-invitation',
            subject: 'Your ERPify invitation',
            textLead: [
                'You have been invited to ERPify.',
                '',
                'Open this link to set your password and sign in:',
            ],
            textTrailer: [
                'If you were not expecting this invitation, you can ignore this message.',
            ],
            htmlLead: 'You have been invited to <strong>ERPify</strong>.',
            htmlDetail: 'Set your password and access your account in a single step.',
            ctaLabel: 'Accept invitation',
            htmlFootnote: 'If you were not expecting this invitation, you can ignore this message.',
        );
    }
}
