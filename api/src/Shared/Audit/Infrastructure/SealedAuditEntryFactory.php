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
 * entry never carries a null correlation id.
 *
 * Sealing here — in the request cycle, not in the off-request worker that persists the entry — is what
 * keeps an activity entry from being mislabelled as a system act.
 */
#[AsAlias(AuditEntryFactory::class)]
final readonly class SealedAuditEntryFactory implements AuditEntryFactory
{
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
            $level,
            $action,
            $this->actorContextFactory->current(),
            $this->resolveCorrelationId(),
            $this->clock->now(),
            $resource?->type,
            $resource?->id,
            $metadata,
        );
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
