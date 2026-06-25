<?php

declare(strict_types=1);

namespace Erpify\Shared\Http\Infrastructure;

use Symfony\Component\HttpFoundation\Request;

/**
 * The single owner of the "is this an `/api` request?" boundary. Every kernel listener that only acts on
 * the API surface — the error-contract responder, the rate limiter, the audit hooks — gates on the same
 * path prefix; centring it here declares that prefix once, so those listeners cannot drift apart when the
 * API mount point moves.
 */
final class ApiRequestMatcher
{
    private const string API_PATH_PREFIX = '/api/';

    public function matches(Request $request): bool
    {
        return \str_starts_with($request->getPathInfo(), self::API_PATH_PREFIX);
    }
}
