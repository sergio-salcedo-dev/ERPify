<?php

declare(strict_types=1);

namespace Erpify\Shared\Mailer\Infrastructure;

use RuntimeException;

/**
 * A fail-loud guard tripped on the security-mail surface: the deploy is misconfigured in a way that would
 * silently weaken a security control — a credential-bearing link over plaintext http, or a "contact us"
 * sender nobody reads — so the send aborts instead of degrading. Raised only outside dev/test. The HTTP
 * recovery flows swallow it through their best-effort wrappers (the uniform response never changes shape);
 * the invitation CLI surfaces it to the operator, who owns the fix.
 */
final class SecurityMailerMisconfigured extends RuntimeException
{
    public static function nonHttpsBaseUrl(): self
    {
        return new self('Security links require an https:// base URL outside dev/test; check DEFAULT_URI.');
    }

    public static function unmonitoredSender(): self
    {
        return new self(
            'MAILER_SECURITY_FROM must be a monitored, replyable mailbox (never empty or no-reply): '
            . 'security emails tell the recipient to contact this address.',
        );
    }

    /**
     * A reserved or link-local domain can receive nothing anywhere. It reads as configured — non-blank, not a
     * no-reply — so the sender guard passes it and the send proceeds to a mailbox that cannot exist.
     */
    public static function undeliverableSenderDomain(string $domain): self
    {
        return new self(\sprintf(
            'MAILER_SECURITY_FROM is at "%s", a reserved domain that can receive no mail outside dev/test: '
            . 'a security email must come from a mailbox the recipient can actually reply to.',
            $domain,
        ));
    }

    /**
     * The discard transport is the dangerous default rather than an exotic misconfiguration: it reports every
     * send as successful, so a caller that records state on a successful send records it for mail that was
     * never transmitted.
     */
    public static function discardingTransport(): self
    {
        return new self(
            'MAILER_DSN is the null:// discard transport, which accepts security mail and drops it while '
            . 'reporting success; set a real transport or security notifications are silently unsent.',
        );
    }
}
