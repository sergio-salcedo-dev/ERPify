<?php

declare(strict_types=1);

namespace Erpify\Shared\Audit\Infrastructure;

use Erpify\Shared\Audit\Application\ActorContextFactory;
use Erpify\Shared\Audit\Application\AuditEntryFactory;
use Erpify\Shared\Audit\Application\AuditLogEntry;
use Erpify\Shared\Audit\Domain\AuditLevel;
use Erpify\Shared\Audit\Domain\AuditResource;
use Erpify\Shared\Clock\Domain\Clock;
use Erpify\Shared\Http\Infrastructure\CorrelationIdListener;
use Erpify\Shared\Uuid\Domain\Uuid;
use Override;
use Symfony\Component\DependencyInjection\Attribute\AsAlias;
use Symfony\Component\HttpFoundation\RequestStack;

/**
 * Production {@see AuditEntryFactory}: seals the trusted context onto each entry inside the request
 * cycle. The actor comes from {@see ActorContextFactory} and the instant from {@see Clock}; the
 * correlation id is the request's canonical id when one is in flight, otherwise a fresh UUIDv7 so the
 * entry never carries a null correlation id. The client `ip` and `user_agent` are read from the same
 * in-flight request — `ip` via `Request::getClientIp()`, the exact trust-boundary decision the
 * rate-limiter already makes (honours `SYMFONY_TRUSTED_PROXIES`, so a spoofed `X-Forwarded-For` cannot
 * forge it) — and are null off-request; both stay tainted downstream.
 *
 * Sealing here — in the request cycle, not in the off-request worker that persists the entry — is what
 * keeps an activity entry from being mislabelled as a system act.
 */
#[AsAlias(AuditEntryFactory::class)]
final readonly class SealedAuditEntryFactory implements AuditEntryFactory
{
    /**
     * Mirrors the `VARCHAR(512)` width of the `user_agent` column, so an over-long (attacker-controlled)
     * header is trimmed to fit here rather than failing the INSERT — which on the synchronous `security`
     * path would turn a denial into a 5xx.
     */
    private const int MAX_USER_AGENT_LENGTH = 512;

    public function __construct(
        private ActorContextFactory $actorContextFactory,
        private Clock $clock,
        private RequestStack $requestStack,
    ) {
    }

    #[Override]
    public function create(
        string $action,
        AuditLevel $level,
        ?AuditResource $resource = null,
        array $metadata = [],
    ): AuditLogEntry {
        return AuditLogEntry::create(
            $action,
            $level,
            $this->actorContextFactory->current(),
            $this->resolveCorrelationId(),
            $this->clock->now(),
            $resource,
            $metadata,
            $this->resolveClientIp(),
            $this->resolveUserAgent(),
        );
    }

    private function resolveClientIp(): ?string
    {
        return $this->requestStack->getCurrentRequest()?->getClientIp();
    }

    private function resolveUserAgent(): ?string
    {
        $userAgent = $this->requestStack->getCurrentRequest()?->headers->get('User-Agent');

        return null === $userAgent ? null : \mb_substr($userAgent, 0, self::MAX_USER_AGENT_LENGTH);
    }

    /**
     * The request's canonical correlation id when one is in flight; otherwise a fresh UUIDv7 so the
     * entry never carries a null correlation id. Off-request a system act is correlated only with itself.
     */
    private function resolveCorrelationId(): string
    {
        $candidate = $this->requestStack->getCurrentRequest()?->attributes->get(CorrelationIdListener::ATTRIBUTE_KEY);

        return \is_string($candidate) && CorrelationIdListener::isCanonical($candidate)
            ? $candidate
            : Uuid::generate();
    }
}
