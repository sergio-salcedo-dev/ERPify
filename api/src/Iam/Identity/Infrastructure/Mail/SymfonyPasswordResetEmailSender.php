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
    public function send(string $recipientEmail, #[SensitiveParameter] string $resetToken): void
    {
        $this->mailer->send($this->content(), $recipientEmail, $resetToken);
    }

    private function content(): SecurityLinkEmailContent
    {
        return new SecurityLinkEmailContent(
            path: '/reset-password',
            subject: 'Restablece tu contraseña de ERPify',
            textLead: [
                'Has solicitado restablecer tu contraseña de ERPify.',
                '',
                'Abre este enlace para definir una nueva contraseña (caduca en una hora):',
            ],
            textTrailer: [
                'Al restablecerla cerraremos todas tus sesiones abiertas por seguridad.',
                'Si no solicitaste este cambio, puedes ignorar este mensaje: tu contraseña no cambiará.',
            ],
            htmlLead: 'Has solicitado restablecer tu contraseña de <strong>ERPify</strong>.',
            htmlDetail: 'Define una nueva contraseña. El enlace caduca en una hora y cerraremos todas '
                . 'tus sesiones abiertas por seguridad.',
            ctaLabel: 'Restablecer contraseña',
            htmlFootnote: 'Si no solicitaste este cambio, puedes ignorar este mensaje: tu contraseña no cambiará.',
        );
    }
}
