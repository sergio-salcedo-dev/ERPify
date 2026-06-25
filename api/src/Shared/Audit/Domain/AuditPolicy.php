<?php

declare(strict_types=1);

namespace Erpify\Shared\Audit\Domain;

/**
 * Decides, before anything is persisted, whether an HTTP interaction is auditable and at what level —
 * the single place the "what is worth a record" criterion lives, so it does not scatter into per-developer
 * choices across the kernel hook and the handlers. A pure decision: no framework, no IO, no container.
 *
 * The hybrid capture has two emitters but one decision. The generic access-log hook on `kernel.terminate`
 * routes every `/api/*` interaction through here; modules also call {@see AuditLogger->log()} explicitly
 * for strong-semantic actions the hook cannot name (a sensitive-data view that needs the resource id, an
 * export, a denial). Where both could fire for the same route, the explicit call is the richer, canonical
 * record, so the generic path must yield rather than write a second, thinner row. Which routes are in that
 * situation is owned by each route's module and declared on its routing metadata, surfaced here as
 * {@see HttpInteraction::$hasCanonicalAudit} — this policy holds no catalogue of concrete module routes,
 * so a new module that wants canonical audit changes its own route, never this shared class.
 *
 * A generic action is the route name promoted to an upper-snake token under a {@see self::GENERIC_ACTION_PREFIX}
 * prefix (`backoffice_bank_search` → `ROUTE_BACKOFFICE_BANK_SEARCH`). The route name is the module-owned
 * identity, so actions "land per module" without a central catalogue; the prefix marks the row, in a forensic
 * export, as navigation captured generically rather than rich domain instrumentation. There is deliberately
 * no generic `HTTP_REQUEST` action — that is the capture-all-decide-late log explosion this policy exists to
 * prevent.
 */
final class AuditPolicy
{
    private const string GENERIC_ACTION_PREFIX = 'ROUTE_';

    public function decide(HttpInteraction $interaction): AuditDecision
    {
        $route = $interaction->routeName;

        if (null === $route || !$this->isGenericActivityRead($interaction, $route)) {
            return AuditDecision::notAuditable();
        }

        return AuditDecision::auditable(AuditLevel::ACTIVITY, $this->genericActionFor($route));
    }

    /**
     * The only interaction this policy audits: a navigational GET to a business-meaningful route that no
     * module is already auditing canonically. Writes, infrastructure noise and canonical routes fall through.
     */
    private function isGenericActivityRead(HttpInteraction $interaction, string $route): bool
    {
        return 'GET' === $interaction->method
            && !$this->lacksBusinessSemantics($route)
            && !$interaction->hasCanonicalAudit;
    }

    /**
     * Infrastructure and UI-plumbing interactions that never belong to an actor's timeline: health checks,
     * Mercure, realtime authorize hops, dev hot-reload, asset/object serving (`shared_*`) and bare count
     * helpers. Classified by absence of business meaning, not by HTTP method — a count endpoint stays
     * non-auditable whether it is its own route or later folded into a payload.
     */
    private function lacksBusinessSemantics(string $route): bool
    {
        return \in_array(true, [
            $this->isHealthCheck($route),
            \str_contains($route, 'mercure'),
            \str_contains($route, 'realtime'),
            \str_contains($route, '_dev_'),
            \str_starts_with($route, 'shared_'),
            \str_ends_with($route, '_count'),
        ], true);
    }

    /**
     * The health-check endpoints are the routes of the `health` module — `backoffice_health`,
     * `backoffice_health_database`, `frontoffice_health`. The `health` token is matched by its position
     * (the module slot, right after the `<context>` prefix), not as a substring: a plain
     * `str_contains($route, 'health')` would also exclude a business route that merely mentions health in
     * a later segment — e.g. `backoffice_merchant_health_score` — silently dropping it from the actor's
     * timeline. This reads the ERPify route-name grammar `<context>_<module>_<action>`.
     */
    private function isHealthCheck(string $route): bool
    {
        return 'health' === (\explode('_', $route)[1] ?? null);
    }

    private function genericActionFor(string $route): string
    {
        return self::GENERIC_ACTION_PREFIX . \strtoupper($route);
    }
}
