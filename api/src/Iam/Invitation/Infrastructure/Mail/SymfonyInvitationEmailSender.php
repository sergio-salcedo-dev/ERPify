<?php

declare(strict_types=1);

namespace Erpify\Iam\Invitation\Infrastructure\Mail;

use Erpify\Iam\Invitation\Application\InvitationEmailSender;
use Override;
use SensitiveParameter;
use Symfony\Component\DependencyInjection\Attribute\AsAlias;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mime\Email;

/**
 * {@see InvitationEmailSender} over `symfony/mailer` (async via Messenger in this stack). Builds the invitation
 * email with a single bulletproof accept link pointing at the PWA's public `accept-invitation` route; the raw
 * token rides only in the emailed URL, never in a log or event. The HTML uses inline styles and a dark-mode
 * media query so it renders in a bare mail client.
 *
 * The `no-reply` sender formalisation and transport-header hardening are a later hardening concern; here it
 * uses the app's configured `MAILER_FROM` and public `DEFAULT_URI`.
 */
#[AsAlias(InvitationEmailSender::class)]
final readonly class SymfonyInvitationEmailSender implements InvitationEmailSender
{
    private const string ACCEPT_PATH = '/accept-invitation';

    private const string SUBJECT = 'Tu invitación a ERPify';

    public function __construct(
        private MailerInterface $mailer,
        #[Autowire('%env(MAILER_FROM)%')]
        private string $mailFrom,
        #[Autowire('%env(DEFAULT_URI)%')]
        private string $appBaseUrl,
    ) {
    }

    #[Override]
    public function send(string $recipientEmail, #[SensitiveParameter] string $acceptToken): void
    {
        $link = \rtrim($this->appBaseUrl, '/') . self::ACCEPT_PATH . '?token=' . \rawurlencode($acceptToken);

        $email = (new Email())
            ->from($this->mailFrom)
            ->to($recipientEmail)
            ->subject(self::SUBJECT)
            ->text($this->textBody($link))
            ->html($this->htmlBody($link))
        ;

        $this->mailer->send($email);
    }

    private function textBody(string $link): string
    {
        return \implode("\n", [
            'Te han invitado a ERPify.',
            '',
            'Abre este enlace para definir tu contraseña y entrar:',
            $link,
            '',
            'Si no esperabas esta invitación, puedes ignorar este mensaje.',
        ]);
    }

    private function htmlBody(string $link): string
    {
        $safeLink = \htmlspecialchars($link, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');

        return <<<HTML
            <!doctype html>
            <html lang="es">
            <head>
            <meta charset="utf-8">
            <meta name="viewport" content="width=device-width, initial-scale=1">
            <style>
              @media (prefers-color-scheme: dark) {
                body { background:#0b0f19 !important; color:#e5e7eb !important; }
                .erpify-btn { background:#6c9bff !important; }
              }
            </style>
            </head>
            <body style="margin:0;padding:24px;background:#f4f5f7;color:#111827;font-family:Arial,sans-serif;">
              <div style="max-width:520px;margin:0 auto;">
                <p style="font-size:16px;line-height:1.5;margin:0 0 16px;">
                  Te han invitado a <strong>ERPify</strong>.
                </p>
                <p style="font-size:16px;line-height:1.5;margin:0 0 24px;">
                  Define tu contraseña y entra en tu cuenta con un solo paso.
                </p>
                <p style="margin:0 0 24px;">
                  <a class="erpify-btn" href="{$safeLink}"
                     style="display:inline-block;padding:14px 28px;background:#2f5cd9;color:#ffffff;
                            text-decoration:none;border-radius:8px;font-size:16px;font-weight:bold;">
                    Aceptar invitación
                  </a>
                </p>
                <p style="font-size:13px;line-height:1.5;color:#6b7280;margin:0 0 8px;">
                  Si el botón no funciona, copia y pega esta dirección en tu navegador:
                </p>
                <p style="font-size:13px;line-height:1.5;word-break:break-all;margin:0 0 24px;">{$safeLink}</p>
                <p style="font-size:12px;line-height:1.5;color:#9ca3af;margin:0;">
                  Si no esperabas esta invitación, puedes ignorar este mensaje.
                </p>
              </div>
            </body>
            </html>
            HTML;
    }
}
