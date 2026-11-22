import { NextResponse, type NextRequest } from "next/server";
import { isDevToolRoute } from "@/context/shared/dev-tools/domain/devToolRoutes";
import { isDevToolsAvailable } from "@/context/shared/dev-tools/domain/isDevToolsAvailable";
import { HttpStatus } from "@/context/shared/http-client/domain/HttpStatus";
import {
  apiOriginFrom,
  buildContentSecurityPolicy,
  mintNonce,
} from "@/context/shared/content-security-policy/domain/contentSecurityPolicy";
import { NodeEnv } from "@/context/shared/environment/domain/NodeEnv";
import { evaluateMonitoringTunnelRequest } from "@/context/shared/rate-limit/infrastructure/monitoringTunnelRateLimiter";

/**
 * Next 16 request proxy (the special file Next runs before the page / route
 * handler). It carries three unrelated edge concerns that both have to run early:
 *
 * 1. **`/monitoring` tunnel rate limit.** The public Sentry tunnel
 *    (`tunnelRoute` in `next.config.ts`) relays anonymous browser POSTs to
 *    Sentry ingest. A per-IP cap (see `monitoringTunnelRateLimiter`) bounds how
 *    fast one source can spray events and burn the Sentry quota; over-limit
 *    POSTs get a `429` with `Retry-After` and never reach the relay. Sentry's
 *    server-side limits remain the second line of defence.
 *
 * 2. **Per-request CSP nonce.** Every page request gets a fresh nonce; the policy
 *    (`buildContentSecurityPolicy`) goes on the REQUEST headers, which is where Next
 *    reads the nonce it stamps onto its own scripts, and on the RESPONSE. The nonce
 *    also travels in `x-nonce` for the root layout (next-themes' inline script).
 *    `next.config.ts` deliberately sets no CSP: a second header would be intersected
 *    with this one. Pages must render per request for this to work (the layout reads
 *    `headers()`), which is the price of dropping `'unsafe-inline'` from `script-src`.
 *
 * 3. **Dev-tools production short-circuit.** In production every dev / QA route
 *    (`/backoffice/dev-tools` and its nested tools, plus the `/dev-throw`
 *    fixture) is rewritten to a guaranteed-unmatched URL before the page handler
 *    runs, so Next renders the branded `app/not-found.tsx` (HTTP 404). This is
 *    defence in depth on top of the page-level `notFound()` guards: if a future
 *    page drops its `isDevToolsAvailable()` check, the surface still 404s. In
 *    development / test it is a pure pass-through, so HMR and the `/dev-throw`
 *    Playwright fixture are unaffected.
 *
 * The `config.matcher` literal MUST be hardcoded — Turbopack only accepts static
 * strings here, so we can't derive it from `DEV_TOOL_ROUTE_PREFIXES`. The parity
 * between this matcher and the domain prefix list is locked by
 * `tests/proxy.test.ts`.
 */
const MONITORING_TUNNEL_PREFIX = "/monitoring";

/** Whether the request is an inbound Sentry tunnel POST (envelope relay). */
function isMonitoringTunnelPost(request: NextRequest): boolean {
  if (request.method !== "POST") {
    return false;
  }
  const { pathname } = request.nextUrl;
  return (
    pathname === MONITORING_TUNNEL_PREFIX || pathname.startsWith(`${MONITORING_TUNNEL_PREFIX}/`)
  );
}

/**
 * `NextResponse.next()` / `rewrite()` that carries the nonce policy on both the
 * forwarded request (read by Next while rendering) and the response (enforced by
 * the browser).
 */
function withContentSecurityPolicy(
  request: NextRequest,
  respond: (init: { request: { headers: Headers } }) => NextResponse,
): NextResponse {
  const nonce = mintNonce();
  const policy = buildContentSecurityPolicy({
    nonce,
    isProduction: process.env.NODE_ENV === NodeEnv.PRODUCTION,
    apiOrigin: apiOriginFrom(process.env.NEXT_PUBLIC_API_BASE_URL),
  });
  const requestHeaders = new Headers(request.headers);
  requestHeaders.set("x-nonce", nonce);
  requestHeaders.set("Content-Security-Policy", policy);
  const response = respond({ request: { headers: requestHeaders } });
  response.headers.set("Content-Security-Policy", policy);
  return response;
}

export function proxy(request: NextRequest): NextResponse {
  if (isMonitoringTunnelPost(request)) {
    const decision = evaluateMonitoringTunnelRequest(request.headers.get("x-forwarded-for"));
    if (!decision.allowed) {
      return new NextResponse(null, {
        status: HttpStatus.TOO_MANY_REQUESTS,
        headers: { "Retry-After": String(decision.retryAfterSeconds) },
      });
    }
    return NextResponse.next();
  }

  if (isDevToolsAvailable() || !isDevToolRoute(request.nextUrl.pathname)) {
    return withContentSecurityPolicy(request, (init) => NextResponse.next(init));
  }
  // Rewrite to a guaranteed-unmatched URL so Next's standard 404
  // handling kicks in and renders the branded `not-found.tsx`.
  const rewriteTarget = new URL("/__erpify-dev-tools-disabled__", request.nextUrl.origin);
  return withContentSecurityPolicy(request, (init) => NextResponse.rewrite(rewriteTarget, init));
}

// Every request except Next's static assets, the image optimiser and the favicon:
// pages need the nonce, `/monitoring` the rate limit, the dev-tool prefixes the
// production 404. (`api` is excluded: Caddy sends `/api/*` to Symfony, never here.)
// Must stay a static literal for Turbopack.
export const config = {
  matcher: ["/((?!api|_next/static|_next/image|favicon.ico).*)"],
};
