import { NextResponse, type NextRequest } from "next/server";
import { isDevToolRoute } from "@/context/shared/dev-tools/domain/devToolRoutes";
import { isDevToolsAvailable } from "@/context/shared/dev-tools/domain/isDevToolsAvailable";

/**
 * Production short-circuit for the dev-tools surface.
 *
 * In production every dev / QA route (`/backoffice/dev-tools` and its nested tools
 * such as `/backoffice/dev-tools/error-gallery`, plus the sibling `/dev-throw`
 * fixture) is rewritten to a guaranteed-unmatched URL **before** the
 * page handler runs. Next then renders the
 * branded `app/not-found.tsx` page with HTTP 404 — exactly what a real
 * user typing the URL by hand should see.
 *
 * Why a proxy layer in addition to the page-level `notFound()` guards?
 * Defence in depth. If a future contributor accidentally drops the
 * `isDevToolsAvailable()` check from a page (or someone adds a new dev
 * route and forgets to gate it), this short-circuit still 404s the
 * request in production and the dev surface stays unreachable.
 *
 * In development / test the proxy is a pure pass-through, so dev
 * ergonomics (HMR, the `/dev-throw` Playwright fixture, the gallery)
 * are unaffected.
 *
 * Naming note: this file used to be `middleware.ts`; Next 16 deprecated
 * that convention and renamed the special file to `proxy.ts`.
 *
 * The `config.matcher` literal MUST be hardcoded — Turbopack only
 * accepts static strings here, so we can't derive it from
 * `DEV_TOOL_ROUTE_PREFIXES`. The parity between this matcher and the
 * domain prefix list is locked by `tests/proxy.test.ts`.
 */
export function proxy(request: NextRequest): NextResponse {
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
  ],
};
