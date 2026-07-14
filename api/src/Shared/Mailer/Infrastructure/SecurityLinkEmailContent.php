<?php

declare(strict_types=1);

namespace Erpify\Shared\Mailer\Infrastructure;

/**
 * The copy that varies between one "single bulletproof link" security email and another (invitation, password
 * reset, …). The scaffold that renders it is shared in {@see SecurityLinkMailer}; only these fragments differ.
 *
 * Every field is trusted, template-author-controlled text — `htmlLead`/`htmlDetail`/`htmlFootnote` may carry
 * inline markup (e.g. `<strong>`) and are emitted verbatim. The untrusted link is assembled from `path` and the
 * token and HTML-escaped by the renderer, never here.
 */
final readonly class SecurityLinkEmailContent
{
    /**
     * @param list<string> $textLead    plain-text lines shown before the link in the text body
     * @param list<string> $textTrailer plain-text lines shown after the link in the text body
     */
    public function __construct(
        public string $path,
        public string $subject,
        public array $textLead,
        public array $textTrailer,
        public string $htmlLead,
        public string $htmlDetail,
        public string $ctaLabel,
        public string $htmlFootnote,
    ) {
    }
}
