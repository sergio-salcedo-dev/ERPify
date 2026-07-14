<?php

declare(strict_types=1);

namespace Erpify\Shared\Mailer\Infrastructure;

/**
 * The shared HTML chrome of every security email: doctype, dark-mode-aware styles, the centred wrapper and the
 * system font stack, plus the one escaping helper untrusted values must pass through. It exists in exactly one
 * place because it carries a security control (the escape) and a UX contract (banking-grade, dark-mode,
 * `lang="es"`): duplicating the scaffold per sender is the regression vector that consolidating it killed.
 * Senders render only their inner paragraphs and delegate the envelope here.
 */
final readonly class BulletproofEmailChrome
{
    public function render(string $innerHtml): string
    {
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
            <body style="margin:0;padding:24px;background:#f4f5f7;color:#111827;
                         font-family:-apple-system,system-ui,'Segoe UI',Roboto,sans-serif;">
              <div style="max-width:520px;margin:0 auto;">
                {$innerHtml}
              </div>
            </body>
            </html>
            HTML;
    }

    /**
     * The single escaping point for any untrusted value a sender interpolates into its inner HTML (today the
     * tokenised link; the rest of every body is template-author-controlled copy emitted verbatim).
     */
    public function escape(string $value): string
    {
        return \htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}
