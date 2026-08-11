<?php

declare(strict_types=1);

namespace Erpify\Shared\Mailer\Infrastructure;

use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * The validated sender of every security email (invitation, reset, password-changed). Security email must come
 * from a monitored, REPLYABLE mailbox — "if this wasn't you, contact us" is part of the copy, so a `no-reply`
 * sender would break its own escape hatch — which is also why this is a separate env var from the operational
 * `MAILER_FROM` (that one is legitimately no-reply).
 *
 * The guard is centralised here rather than repeated per sender because it is a policy control shared by every
 * consumer of the env var: outside the local environments a blank or no-reply value fails loudly instead of
 * silently degrading the contract.
 */
final readonly class SecuritySenderAddress
{
    /**
     * Local stacks seed placeholder senders and run over `http://localhost` by design; the fail-loud guards
     * target a misconfigured deploy, not the developer loop.
     */
    private const array LOCAL_ENVIRONMENTS = ['dev', 'test'];

    /**
     * Domains that resolve nowhere on the public internet: the RFC 2606 / RFC 6761 reserved names plus `.local`
     * (RFC 6762, link-local mDNS). The repository's own default sender sits at `erpify.local`, which is
     * non-blank and is not a no-reply, so it satisfies every other check on this class while being a mailbox no
     * recipient can reply to.
     */
    private const array UNDELIVERABLE_DOMAINS = ['test', 'example', 'invalid', 'localhost', 'local'];

    public function __construct(
        #[Autowire('%env(MAILER_SECURITY_FROM)%')]
        private string $address,
        #[Autowire('%kernel.environment%')]
        private string $environment,
    ) {
    }

    public function toString(): string
    {
        if (\in_array($this->environment, self::LOCAL_ENVIRONMENTS, true)) {
            return $this->address;
        }

        if (
            '' === \trim($this->address) || \str_contains(\strtolower($this->address), 'noreply')
            || \str_contains(\strtolower($this->address), 'no-reply')
        ) {
            throw SecurityMailerMisconfigured::unmonitoredSender();
        }

        $domain = $this->domain();

        if (\in_array($domain, self::UNDELIVERABLE_DOMAINS, true)) {
            throw SecurityMailerMisconfigured::undeliverableSenderDomain($domain);
        }

        return $this->address;
    }

    /** The last label of the address's domain, lower-cased; empty when there is no domain part at all. */
    private function domain(): string
    {
        $at = \strrpos($this->address, '@');

        if (false === $at) {
            return '';
        }

        $labels = \explode('.', \strtolower(\substr($this->address, $at + 1)));

        return \end($labels);
    }
}
