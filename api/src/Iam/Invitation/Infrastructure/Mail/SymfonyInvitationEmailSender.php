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
    public function send(string $recipientEmail, #[SensitiveParameter] string $acceptToken): void
    {
        $this->mailer->send($this->content(), $recipientEmail, $acceptToken);
    }

    private function content(): SecurityLinkEmailContent
    {
        return new SecurityLinkEmailContent(
            path: '/accept-invitation',
            subject: 'Tu invitación a ERPify',
            textLead: [
                'Te han invitado a ERPify.',
                '',
                'Abre este enlace para definir tu contraseña y entrar:',
            ],
            textTrailer: [
                'Si no esperabas esta invitación, puedes ignorar este mensaje.',
            ],
            htmlLead: 'Te han invitado a <strong>ERPify</strong>.',
            htmlDetail: 'Define tu contraseña y entra en tu cuenta con un solo paso.',
            ctaLabel: 'Aceptar invitación',
            htmlFootnote: 'Si no esperabas esta invitación, puedes ignorar este mensaje.',
        );
    }
}
