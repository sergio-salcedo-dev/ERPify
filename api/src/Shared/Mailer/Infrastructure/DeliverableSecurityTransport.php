<?php

declare(strict_types=1);

namespace Erpify\Shared\Mailer\Infrastructure;

use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Asserts that this deployment has a transport that can actually carry a security email, before one is handed
 * to it.
 *
 * **The discard transport is the danger, and it is the default.** `null://null` implements the mailer contract
 * by doing nothing and returning normally — Symfony's own `NullTransport` describes itself as pretending
 * messages have been sent — so every layer above it observes a success. That is tolerable for mail whose
 * absence someone notices, and it is not tolerable here: a caller that records state on a confirmed send
 * records it for a message that was never transmitted. The lockout notice does exactly that, and its
 * suppression stamp then withholds the next attempt for a day.
 *
 * The failure is invisible from the other end too. Security mail on these paths is **unsolicited** — nobody is
 * waiting for a lockout notice the way they wait for a password reset — so non-delivery produces no complaint
 * and no support ticket. Nothing but this check would ever contradict a deployment that silently sends none of
 * it.
 *
 * A guard at send time rather than at boot, and that is deliberate: it is retried on every attempt, so
 * repairing the configuration repairs the control with no redeploy, and each refusal logs through the
 * best-effort wrapper that already catches it. That log lands on `php://stderr` and nowhere else: the
 * Monolog-to-Sentry handler in `monolog.yaml` is commented out, and `register_error_listener` sees only
 * unhandled throwables, which a swallowed one is not. So a schedule that cannot do its job is an operational
 * fault visible in the container log rather than an alert. A one-shot check at startup states the same fact
 * once and then goes quiet while the deployment drifts.
 *
 * Separate from {@see SecuritySenderAddress} because they answer different questions about different values:
 * one is whether a channel exists, the other is who the message claims to be from. A deployment can get either
 * wrong on its own.
 */
final readonly class DeliverableSecurityTransport
{
    /** Local stacks discard mail into Mailpit or nowhere by design; the guard targets a deploy, not the loop. */
    private const array LOCAL_ENVIRONMENTS = ['dev', 'test'];

    private const string DISCARD_SCHEME = 'null://';

    public function __construct(
        #[Autowire(env: 'MAILER_DSN')]
        private string $dsn,
        #[Autowire(param: 'kernel.environment')]
        private string $environment,
    ) {
    }

    public function ensureDeliverable(): void
    {
        if (\in_array($this->environment, self::LOCAL_ENVIRONMENTS, true)) {
            return;
        }

        if (\str_starts_with(\strtolower(\trim($this->dsn)), self::DISCARD_SCHEME)) {
            throw SecurityMailerMisconfigured::discardingTransport();
        }
    }
}
