<?php

declare(strict_types=1);

namespace Erpify\Shared\Audit\Domain;

/**
 * The framework-free projection of an HTTP request that {@see AuditPolicy} classifies: the matched
 * route name (the stable, module-owned semantic identity of the interaction), the HTTP method, and
 * whether that route declares its own canonical audit. An unmatched request — a static asset, a proxy
 * hop, anything Symfony routed nowhere — carries a null {@see $routeName}; the policy treats that as
 * technical and never audits it.
 *
 * {@see $hasCanonicalAudit} is the route's own declaration that an explicit {@see AuditLogger->log()}
 * call already records this interaction richly, so the generic access-log path must yield rather than
 * write a second, thinner row. The fact is owned by the route's module and read off its routing
 * metadata in Infrastructure; the policy only consumes it, so {@see AuditPolicy} holds no list of which
 * concrete module routes are special.
 *
 * Kept in Domain as a pure value object so the auditability decision is unit-testable without a kernel,
 * a container or a real {@see \Symfony\Component\HttpFoundation\Request}; the capture subscriber in
 * Infrastructure is the only place a Symfony request is mapped onto this shape.
 */
final readonly class HttpInteraction
{
    public function __construct(
        public ?string $routeName,
        public string $method,
        public bool $hasCanonicalAudit,
    ) {
    }
}
