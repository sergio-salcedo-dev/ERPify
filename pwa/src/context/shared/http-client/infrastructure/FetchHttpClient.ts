import { inject, injectable } from "inversify";
import {
  isProblemDetails,
  type ProblemDetails,
} from "@/context/shared/error/domain/ProblemDetails";
import { HttpError } from "../domain/HttpError";
import {
  MALFORMED_RESPONSE_ENVELOPE,
  NETWORK_ERROR,
  type HttpClient,
  type ResponseGuard,
} from "../domain/HttpClient";
import { HttpStatus } from "../domain/HttpStatus";
import type { DebugTokenObserver } from "@/context/shared/debug-token/domain/DebugTokenObserver";
import { NoopDebugTokenObserver } from "@/context/shared/debug-token/infrastructure/NoopDebugTokenObserver";
import type { Telemetry } from "@/context/shared/observability/domain/Telemetry";
import { NoopTelemetry } from "@/context/shared/observability/infrastructure/NoopTelemetry";
import { apiScope } from "@/context/shared/observability/domain/TelemetryScope";
import { uuidV7 } from "@/context/shared/uuid/infrastructure/uuidV7";
import { Routes } from "@/context/shared/routing/domain/Routes";
import { safeInternalPath } from "@/context/shared/navigation/domain/safeInternalPath";
import { API_ENDPOINTS } from "./ApiEndpoints";

function trimBase(url: string): string {
  return url.replace(/\/$/, "");
}

// Single-flight guard for the session-expired bounce: a burst of concurrent
// 401s (e.g. a dashboard firing several gated requests at once) must navigate to
// /login exactly once, not race N redirects. Cleared again only if the navigation is
// refused, because then the page is NOT leaving and every later 401 would be swallowed.
let sessionExpiredRedirectStarted = false;

// Endpoints whose 401/403 is a handshake outcome, not a mid-session expiry:
//  - `/me` is the cold-load probe the AuthProvider owns; a redirect here would
//    loop the unauthenticated landing (AuthProvider sets `unauthenticated` and
//    RequireAuth does the routing).
//  - `/backoffice/login` reports bad credentials on the login page itself.
//  - `/backoffice/invitations/accept` and `/backoffice/reset-password` run for
//    an as-yet-unauthenticated user; an origin/CSRF rejection is a handshake
//    failure the token-action screen owns, so it must not be bounced to
//    `/login?reason=session-expired`.
//  - `/sessions/revoke-current` IS the sign-out call. On an already-expired
//    session it 401s, and bouncing that would race the sign-out's own
//    navigation and strand the user on "session expired" instead of the public
//    landing they asked for.
// Every other gated 401 means "was authenticated, now isn't".
function isAuthHandshakeEndpoint(input: string): boolean {
  const path = input.split("?")[0];
  return (
    path.endsWith(API_ENDPOINTS.IDENTITY.ME) ||
    path.endsWith(API_ENDPOINTS.BACKOFFICE.LOGIN) ||
    path.endsWith(API_ENDPOINTS.BACKOFFICE.INVITATIONS.ACCEPT) ||
    path.endsWith(API_ENDPOINTS.BACKOFFICE.RESET_PASSWORD) ||
    path.endsWith(API_ENDPOINTS.IDENTITY.SESSIONS_REVOKE_CURRENT)
  );
}

// Browser-only: bounce an expired session to /login once, preserving the blocked
// target in `?next=` (open-redirect-guarded) and flagging the reason. No-op during
// SSR (no document/location) and for the auth-handshake endpoints above.
function redirectToLoginOnSessionExpiry(input: string): void {
  if (typeof window === "undefined") return;
  if (sessionExpiredRedirectStarted) return;
  if (isAuthHandshakeEndpoint(input)) return;
  // Already on /login: the bounce would replace the document with itself, and the latch
  // is module state that a fresh document resets — so a 401 raised from this screen could
  // reload it for as long as the call keeps failing.
  if (globalThis.location.pathname === Routes.LOGIN) return;
  sessionExpiredRedirectStarted = true;
  const current = `${globalThis.location.pathname}${globalThis.location.search}`;
  const next = encodeURIComponent(safeInternalPath(current, Routes.BACKOFFICE));
  // A router is unreachable from here: this is a module-level function inside an
  // infrastructure adapter, so there is no render phase for redirect() and no React
  // context for useRouter(). A full-document navigation is also what this path wants —
  // the session is gone, so discarding every piece of in-memory client state is the
  // point, not a side effect. replace() rather than assign() so the dead, now
  // unauthenticated page does not sit in history one Back press away.
  try {
    // eslint-disable-next-line no-restricted-syntax
    globalThis.location.replace(`${Routes.LOGIN}?next=${next}&reason=session-expired`);
  } catch {
    // No known browser path reaches here: unlike assign(), replace() is cross-origin
    // callable and performs no security check, and a sandboxed navigable *ignores* a
    // navigation rather than raising. It stands so that an environment which cannot
    // navigate at all cannot escape this adapter's HttpError contract with a raw
    // exception, and it drops the latch because the document stayed. It does NOT cover
    // the ignored-navigation case: nothing is raised there, so the latch survives and
    // later 401s in that document are swallowed.
    sessionExpiredRedirectStarted = false;
  }
}

function browserApiBase(): string {
  const v = process.env.NEXT_PUBLIC_API_BASE_URL?.trim();
  // why: same-origin by default. A hardcoded https://localhost broke any
  // deployment on a non-default port (e.g. worktree stacks on :8443) because
  // the CSP connect-src only allows 'self' — the cross-origin fetch was
  // blocked and banks/health never loaded. A relative base ("") is correct by
  // construction same-origin (FrankenPHP serves /api on the same origin), and
  // mirrors what BrowserMercureSubscriber / frankenphp-hot-reload already do.
  // NEXT_PUBLIC_API_BASE_URL remains the explicit cross-origin override (the
  // CSP already emits that origin in connect-src when the var is set).
  return trimBase(v || "");
}

function serverApiBase(): string {
  const internal = process.env.SYMFONY_INTERNAL_URL?.trim();
  if (internal) {
    return trimBase(internal);
  }
  const browser = browserApiBase();
  if (browser) {
    return browser;
  }
  // The server path cannot issue a relative request — there is no document
  // origin during SSR / route handlers. Rather than silently targeting
  // https://localhost:443 (which a worktree/staging stack never serves), fail
  // fast and name the two env vars an operator can set. This throws only on an
  // actual server-side fetch with both unset — never at module-init — so the
  // singleton constructed by the DI container still boots.
  throw new Error(
    "Cannot resolve a server-side API base URL: set SYMFONY_INTERNAL_URL " +
      "(internal SSR target, e.g. http://php:80) or NEXT_PUBLIC_API_BASE_URL " +
      "(public override). Neither is set.",
  );
}

@injectable()
export class FetchHttpClient implements HttpClient {
  // Optional + defaulted so the ~20 direct `new FetchHttpClient()` call sites in
  // the unit suite keep working; the container always binds a real observer, so
  // production/dev resolution never hits the default.
  constructor(
    @inject("DebugTokenObserver")
    private readonly debugTokens: DebugTokenObserver = new NoopDebugTokenObserver(),
    @inject("Telemetry")
    private readonly telemetry: Telemetry = new NoopTelemetry(),
  ) {}

  // Single fetch chokepoint: every request reads the Symfony profiler token off
  // the response (success and error paths share this) and publishes it for the
  // dev-only toolbar. No-op in prod (header absent + inert observer). It is also
  // where a session-expiry 401 bounces the browser to /login exactly once.
  private async request(input: string, init: RequestInit): Promise<Response> {
    let res: Response;
    try {
      res = await fetch(input, init);
    } catch (cause) {
      this.telemetry.error("Transport failure reaching the API", {
        scope: apiScope("transport"),
        cause,
      });
      throw this.transportError();
    }
    const token = res.headers.get("X-Debug-Token");
    if (token) {
      this.debugTokens.publish({ token, profilerUrl: res.headers.get("X-Debug-Token-Link") });
    }
    if (res.status === HttpStatus.UNAUTHORIZED) {
      redirectToLoginOnSessionExpiry(input);
    }
    return res;
  }

  async get<T>(url: string, validate?: ResponseGuard<T>): Promise<T> {
    const res = await this.request(this.resolveUrl(url), {
      headers: { Accept: "application/json" },
      cache: "no-store",
    });

    if (!res.ok) {
      throw await this.toHttpError(res);
    }

    return this.parseBody<T>(res, url, validate);
  }

  async post<TBody, T>(
    url: string,
    body: TBody,
    validate?: ResponseGuard<T>,
    headers?: Record<string, string>,
  ): Promise<T> {
    return this.sendWithBody<TBody, T>("POST", url, body, validate, headers);
  }

  async put<TBody, T>(url: string, body: TBody, validate?: ResponseGuard<T>): Promise<T> {
    return this.sendWithBody<TBody, T>("PUT", url, body, validate);
  }

  async patch<TBody, T>(url: string, body: TBody, validate?: ResponseGuard<T>): Promise<T> {
    return this.sendWithBody<TBody, T>("PATCH", url, body, validate);
  }

  async delete(url: string): Promise<void> {
    const res = await this.request(this.resolveUrl(url), {
      method: "DELETE",
      headers: { Accept: "application/json" },
      cache: "no-store",
    });

    if (!res.ok) {
      throw await this.toHttpError(res);
    }
  }

  private async sendWithBody<TBody, T>(
    method: "POST" | "PUT" | "PATCH",
    url: string,
    body: TBody,
    validate?: ResponseGuard<T>,
    headers?: Record<string, string>,
  ): Promise<T> {
    const res = await this.request(this.resolveUrl(url), {
      method,
      headers: {
        // Caller headers first: the body is always JSON.stringify'd, so Accept and Content-Type
        // describe what this client actually sends and a caller must not be able to contradict
        // them — a "text/plain" override would ship a JSON body the API then refuses as 415.
        ...headers,
        Accept: "application/json",
        "Content-Type": "application/json",
      },
      cache: "no-store",
      body: JSON.stringify(body),
    });

    if (!res.ok) {
      throw await this.toHttpError(res);
    }

    return this.parseBody<T>(res, url, validate);
  }

  // why: a 2xx whose body drifted from the expected envelope must surface as a
  // typed boundary error here, not as a TypeError deep inside a mapper (e.g. a
  // stale browser bundle fetching a newer, reshaped API response).
  private async parseBody<T>(res: Response, url: string, validate?: ResponseGuard<T>): Promise<T> {
    const raw = await res.text();

    if (!validate) {
      // why: a guard-less caller (e.g. get<void>) tolerates an empty body on ANY 2xx, not only a
      // 204 — an endpoint answering 200 with no payload must not surface as a malformed envelope.
      // Only a non-empty body that fails to parse does.
      if (raw.trim() === "") {
        return undefined as T;
      }

      try {
        return JSON.parse(raw) as T;
      } catch {
        // why: a guard-less caller still relies on this boundary's typed-error
        // contract — an unparseable 2xx body must not leak a raw SyntaxError.
        throw this.malformedEnvelope(res, `Unparseable JSON response body for ${url}`);
      }
    }

    let parsed: unknown;
    try {
      parsed = JSON.parse(raw);
    } catch {
      // why: an unparseable body and a body that parsed but failed the guard are distinct
      // failures — report them with the matching detail so triage isn't misled. An empty body
      // under a guard is unparseable too (a guard means a body of shape T is expected).
      throw this.malformedEnvelope(res, `Unparseable JSON response body for ${url}`);
    }

    if (!validate(parsed)) {
      throw this.malformedEnvelope(res, `Unexpected response body shape for ${url}`);
    }

    return parsed;
  }

  private malformedEnvelope(res: Response, detail: string): HttpError {
    return new HttpError({
      type: MALFORMED_RESPONSE_ENVELOPE,
      title: "API response did not match the expected shape",
      status: res.status,
      detail,
      instance: uuidV7(),
      "correlation-id": this.correlationId(res),
    });
  }

  // why: a transport failure (offline / DNS / CORS / server down) rejects fetch
  // with a raw TypeError, not an HttpError — without this, form catch-guards
  // re-throw it as an unhandled rejection and the submit dies silently. status 0
  // is the "no response" sentinel ProblemDisplay renders as "No response"; with
  // no Response there is no X-Correlation-Id to join, so a client v7 is minted.
  private transportError(): HttpError {
    return new HttpError({
      type: NETWORK_ERROR,
      title: "Could not reach the server",
      status: 0,
      detail: "Check your connection and try again.",
      instance: uuidV7(),
      "correlation-id": uuidV7(),
    });
  }

  // why: prefer the server's X-Correlation-Id so the surfaced error joins the same server-log
  // span. Only when the response carries none (a proxy stripped it, or the request never reached
  // the API's correlation-id listener) is a client-side v7 minted — a last resort that, by
  // construction, matches no server log. The ProblemDetails contract requires a string, so
  // omitting the field is not an option.
  private correlationId(res: Response): string {
    return res.headers.get("X-Correlation-Id") ?? uuidV7();
  }

  private resolveUrl(url: string): string {
    // Resolved per call, not in the constructor: the singleton may be built at
    // module-init (no request scope) while the browser/server distinction and
    // the fail-fast env check must only run when an actual fetch is issued.
    const baseUrl = globalThis.window === undefined ? serverApiBase() : browserApiBase();
    const path = url.startsWith("/") ? url : `/${url}`;
    return `${baseUrl}${path}`;
  }

  private async toHttpError(res: Response): Promise<HttpError> {
    const parsed = await res.json().catch(() => null);
    const problem: ProblemDetails = isProblemDetails(parsed)
      ? parsed
      : {
          type: "about:blank",
          title: `HTTP ${res.status}`,
          status: res.status,
          instance: uuidV7(),
          "correlation-id": this.correlationId(res),
        };
    return new HttpError(problem);
  }
}
