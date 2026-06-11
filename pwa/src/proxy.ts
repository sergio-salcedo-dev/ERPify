import { NextResponse, type NextRequest } from "next/server";
import { isDevToolRoute } from "@/context/shared/dev-tools/domain/devToolRoutes";
import { isDevToolsAvailable } from "@/context/shared/dev-tools/domain/isDevToolsAvailable";
import { HttpStatus } from "@/context/shared/domain/types/http";
import { evaluateMonitoringTunnelRequest } from "@/context/shared/infrastructure/RateLimit/monitoringTunnelRateLimiter";

/**
 * Next 16 request proxy (the special file Next runs before the page / route
 * handler). It carries two unrelated edge concerns that both have to run early:
 *
 * 1. **`/monitoring` tunnel rate limit.** The public Sentry tunnel
 *    (`tunnelRoute` in `next.config.ts`) relays anonymous browser POSTs to
 *    Sentry ingest. A per-IP cap (see `monitoringTunnelRateLimiter`) bounds how
 *    fast one source can spray events and burn the Sentry quota; over-limit
 *    POSTs get a `429` with `Retry-After` and never reach the relay. Sentry's
 *    server-side limits remain the second line of defence.
 *
 * 2. **Dev-tools production short-circuit.** In production every dev / QA route
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

  if (isDevToolsAvailable()) {
    return NextResponse.next();
  }
  if (!isDevToolRoute(request.nextUrl.pathname)) {
    return NextResponse.next();
  }
  // Rewrite to a guaranteed-unmatched URL so Next's standard 404
  // handling kicks in and renders the branded `not-found.tsx`.
  const rewriteTarget = new URL("/__erpify-dev-tools-disabled__", request.nextUrl.origin);
  return NextResponse.rewrite(rewriteTarget);
}

export const config = {
  matcher: [
    "/backoffice/dev-tools",
    "/backoffice/dev-tools/:path*",
    "/dev-throw",
    "/dev-throw/:path*",
    "/monitoring",
    "/monitoring/:path*",
  ],
};
