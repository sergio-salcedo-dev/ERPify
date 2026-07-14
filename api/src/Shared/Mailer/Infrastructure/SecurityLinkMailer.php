<?php

declare(strict_types=1);

namespace Erpify\Shared\Mailer\Infrastructure;

use SensitiveParameter;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mime\Email;

/**
 * Renders and sends a "single bulletproof link" security email — a plain-text fallback plus a dark-mode-aware
 * HTML body with one CTA button and a copy-paste link fallback. Each caller (the invitation and password-reset
 * adapters) supplies only its copy and route via {@see SecurityLinkEmailContent}; the shared envelope lives in
 * {@see BulletproofEmailChrome} and the sender policy in {@see SecuritySenderAddress}.
 *
 * The link is assembled from the app base URL and the token and HTML-escaped in the body — the raw token rides
 * only in that emailed URL, never in a log or event. The send is synchronous on purpose: routing Symfony's
 * `SendEmailMessage` to a queued transport would serialise the whole Email — plaintext token included — into
 * the transport table and the `failed` queue, so a token-bearing email must never ride a transport. Callers
 * wrap the send best-effort, which is what keeps their uniform response from turning a mailer fault into a 500.
 */
final readonly class SecurityLinkMailer
{
    /**
     * @see SecuritySenderAddress::LOCAL_ENVIRONMENTS — same rationale: dev/test run on `http://localhost`
     * by design, so the HTTPS guard targets a misconfigured deploy, not the developer loop.
     */
    private const array LOCAL_ENVIRONMENTS = ['dev', 'test'];

    public function __construct(
        private MailerInterface $mailer,
        private SecuritySenderAddress $securityFrom,
        #[Autowire('%env(DEFAULT_URI)%')]
        private string $appBaseUrl,
        private BulletproofEmailChrome $chrome,
        #[Autowire('%kernel.environment%')]
        private string $environment,
    ) {
    }

    public function send(
        SecurityLinkEmailContent $content,
        string $recipientEmail,
        #[SensitiveParameter]
        string $token,
    ): void {
        // Defence in depth over the single link-assembly point: a deploy whose base URL is not HTTPS must
        // fail loudly here rather than email a credential-bearing link an on-path attacker could read.
        if (
            !\in_array($this->environment, self::LOCAL_ENVIRONMENTS, true)
            && !\str_starts_with($this->appBaseUrl, 'https://')
        ) {
            throw SecurityMailerMisconfigured::nonHttpsBaseUrl();
        }

        $link = \rtrim($this->appBaseUrl, '/') . $content->path . '?token=' . \rawurlencode($token);

        $email = (new Email())
            ->from($this->securityFrom->toString())
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
        $safeLink = $this->chrome->escape($link);

        return $this->chrome->render(<<<HTML
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
            HTML);
    }
}
