<?php

declare(strict_types=1);

namespace Erpify\Shared\Mailer\Infrastructure;

use SensitiveParameter;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mime\Email;

/**
 * Renders and sends a "single bulletproof link" security email — a plain-text fallback plus a dark-mode-aware
 * HTML body with one CTA button and a copy-paste link fallback. The scaffold lives here once; each caller (the
 * invitation and password-reset adapters) supplies only its copy and route via {@see SecurityLinkEmailContent}.
 *
 * The link is assembled from the app base URL and the token and HTML-escaped in the body — the raw token rides
 * only in that emailed URL, never in a log or event. The send is synchronous today because Symfony's
 * `SendEmailMessage` is not routed to a transport in this stack.
 */
final readonly class SecurityLinkMailer
{
    public function __construct(
        private MailerInterface $mailer,
        #[Autowire('%env(MAILER_FROM)%')]
        private string $mailFrom,
        #[Autowire('%env(DEFAULT_URI)%')]
        private string $appBaseUrl,
    ) {
    }

    public function send(
        SecurityLinkEmailContent $content,
        string $recipientEmail,
        #[SensitiveParameter]
        string $token,
    ): void {
        $link = \rtrim($this->appBaseUrl, '/') . $content->path . '?token=' . \rawurlencode($token);

        $email = (new Email())
            ->from($this->mailFrom)
            ->to($recipientEmail)
            ->subject($content->subject)
            ->text($this->textBody($content, $link))
            ->html($this->htmlBody($content, $link))
        ;

        $this->mailer->send($email);
    }

    private function textBody(SecurityLinkEmailContent $content, string $link): string
    {
        return \implode("\n", [...$content->textLead, $link, '', ...$content->textTrailer]);
    }

    private function htmlBody(SecurityLinkEmailContent $content, string $link): string
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
                  {$content->htmlLead}
                </p>
                <p style="font-size:16px;line-height:1.5;margin:0 0 24px;">
                  {$content->htmlDetail}
                </p>
                <p style="margin:0 0 24px;">
                  <a class="erpify-btn" href="{$safeLink}"
                     style="display:inline-block;padding:14px 28px;background:#2f5cd9;color:#ffffff;
                            text-decoration:none;border-radius:8px;font-size:16px;font-weight:bold;">
                    {$content->ctaLabel}
                  </a>
                </p>
                <p style="font-size:13px;line-height:1.5;color:#6b7280;margin:0 0 8px;">
                  Si el botón no funciona, copia y pega esta dirección en tu navegador:
                </p>
                <p style="font-size:13px;line-height:1.5;word-break:break-all;margin:0 0 24px;">{$safeLink}</p>
                <p style="font-size:12px;line-height:1.5;color:#9ca3af;margin:0;">
                  {$content->htmlFootnote}
                </p>
              </div>
            </body>
            </html>
            HTML;
    }
}
