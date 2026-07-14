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
}
