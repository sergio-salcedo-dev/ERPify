<?php

declare(strict_types=1);

namespace Erpify\Shared\Audit\Infrastructure\Http;

use Symfony\Component\HttpFoundation\Request;

/**
 * The single owner, for the audit listeners, of "is this an `/api` request?". Both the access-log
 * ({@see EventListener\AccessLogAuditListener}) and the access-denied
 * ({@see EventListener\AccessDeniedAuditListener}) hooks gate on the same path boundary; centring it
 * here means the prefix is declared once, so the two cannot drift apart when the API mount point moves.
 *
 * Scoped to the audit subsystem on purpose: `Shared/ErrorContract` carries its own copy of this boundary
 * for the error pipeline, and folding both subsystems onto one matcher is a separate change, not part of
 * the audit work.
 */
final class ApiRequestMatcher
{
    private const string API_PATH_PREFIX = '/api/';

    public function matches(Request $request): bool
    {
        return \str_starts_with($request->getPathInfo(), self::API_PATH_PREFIX);
    }
}
