import { inject, injectable } from "inversify";
import {
  isProblemDetails,
  type ProblemDetails,
} from "@/context/shared/error/domain/ProblemDetails";
import { HttpError } from "../domain/HttpError";
import {
  MALFORMED_RESPONSE_ENVELOPE,
  NETWORK_ERROR,
  REQUEST_TIMEOUT,
  type HttpClient,
  type RequestOptions,
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
import { hardNavigate } from "@/context/shared/navigation/infrastructure/hardNavigate";
import {
  beginSessionExpiry,
  endSessionExpiry,
} from "@/context/shared/access/application/sessionExpiry";
import { API_ENDPOINTS } from "./ApiEndpoints";

function trimBase(url: string): string {
  return url.replace(/\/$/, "");
}

// How long any single request is given before the client gives up on it. A default, not a
// policy: it exists because "no request hangs forever" is a transport invariant and this
// module is the only place that can hold it for every caller. Before it, the sole enforcer
// in the tree was a timer owned by a layout component, which bounded exactly one call.
const DEFAULT_TIMEOUT_MS = 30_000;

/** A response whose body has already been drained, inside the request's budget. */
interface ReadResponse {
  res: Response;
  raw: string;
}

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
  if (isAuthHandshakeEndpoint(input)) return;
  // Already on /login: the bounce would replace the document with itself, and the claim is
  // module state that a fresh document resets — so a 401 raised from this screen could
  // reload it for as long as the call keeps failing.
  if (globalThis.location.pathname === Routes.LOGIN) return;
  if (!beginSessionExpiry()) return;
  const current = `${globalThis.location.pathname}${globalThis.location.search}`;
  const next = encodeURIComponent(safeInternalPath(current, Routes.BACKOFFICE));
  // A router is unreachable from here: this is a module-level function inside an
  // infrastructure adapter, so there is no render phase for redirect() and no React
  // context for useRouter(). A full-document navigation is also what this path wants —
  // the session is gone, so discarding every piece of in-memory client state is the
  // point, not a side effect. replace() rather than assign() so the dead, now
  // unauthenticated page does not sit in history one Back press away.
  //
  // Releasing the claim on failure covers BOTH ways the document can stay: a refusal, which
  // raises, and an ignored navigation, which does not. The second is the one that used to
  // wedge this adapter — every later 401 in the document swallowed, no bounce and no signal.
  hardNavigate(`${Routes.LOGIN}?next=${next}&reason=session-expired`, endSessionExpiry);
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
  // where a request is bounded, and where a session-expiry 401 bounces the browser to
  // /login exactly once.
  //
  // The BODY is read here, inside the budget, and handed back with the response — not
  // left for the callers to read afterwards. `fetch()` resolves on response HEADERS, so
  // clearing the timer at that point armed the abort for the handshake and nothing else:
  // a response whose headers land and whose body never completes (a chunked reply held
  // open by a proxy, a half-closed connection) left the promise pending for ever, with
  // the controller already unreferenced. That is the whole invariant this module claims,
  // failing in the one direction nobody would look.
  private async request(
    input: string,
    init: RequestInit,
    options?: RequestOptions,
  ): Promise<ReadResponse> {
    const timeoutMs = options?.timeoutMs ?? DEFAULT_TIMEOUT_MS;
    // An AbortController rather than `AbortSignal.timeout()`: the abort has to be
    // distinguishable from an offline/DNS failure once fetch rejects, and reading the
    // controller's own signal is what tells the two apart without matching on an error name.
    const controller = new AbortController();
    const timer = setTimeout(() => controller.abort(), timeoutMs);
    // Hoisted so the catch block below can tell a body-read abort that already saw a 401
    // apart from one that never observed any status at all.
    let res: Response | undefined;
    try {
      res = await fetch(input, { ...init, signal: controller.signal });
      const token = res.headers.get("X-Debug-Token");
      if (token) {
        this.debugTokens.publish({ token, profilerUrl: res.headers.get("X-Debug-Token-Link") });
      }
      if (res.status === HttpStatus.UNAUTHORIZED) {
        // The 401 still travels back to its caller and still throws: the HTTP contract is
        // unchanged, so no caller has to learn a second failure shape. What stops the error UI
        // painting during the unload window is <SessionExpiryCurtain>, which reads the same
        // claim this call publishes.
        redirectToLoginOnSessionExpiry(input);
      }
      // Aborting mid-stream rejects this too, which is the point: it is inside the try, so
      // a stalled body surfaces as the adapter's own timeout rather than escaping as a raw
      // DOMException from a caller reading the body later.
      const raw = await res.text();
      return { res, raw };
    } catch (cause) {
      if (controller.signal.aborted) {
        // The status line already landed and it was a 401. Reporting this as a timeout would
        // be the wrong shape regardless of whether this call site bounces the browser
        // (`redirectToLoginOnSessionExpiry` is a deliberate no-op for the auth-handshake
        // endpoints above, `revoke-current` included) — an empty body still resolves to the
        // same 401 through the ordinary !res.ok path below, matching what the status line
        // already said.
        if (res?.status === HttpStatus.UNAUTHORIZED) {
          return { res, raw: "" };
        }
        this.telemetry.error("The API did not answer within the request budget", {
          scope: apiScope("transport"),
          cause,
        });
        throw this.timeoutError(timeoutMs);
      }
      this.telemetry.error("Transport failure reaching the API", {
        scope: apiScope("transport"),
        cause,
      });
      throw this.transportError();
    } finally {
      clearTimeout(timer);
    }
  }

  async get<T>(url: string, validate?: ResponseGuard<T>, options?: RequestOptions): Promise<T> {
    const { res, raw } = await this.request(
      this.resolveUrl(url),
      {
        headers: { ...options?.headers, Accept: "application/json" },
        cache: "no-store",
      },
      options,
    );

    if (!res.ok) {
      throw this.toHttpError(res, raw);
    }

    return this.parseBody<T>(res, raw, url, validate);
  }

  async post<TBody, T>(
    url: string,
    body: TBody,
    validate?: ResponseGuard<T>,
    options?: RequestOptions,
  ): Promise<T> {
    return this.sendWithBody<TBody, T>("POST", url, body, validate, options);
  }

  async put<TBody, T>(
    url: string,
    body: TBody,
    validate?: ResponseGuard<T>,
    options?: RequestOptions,
  ): Promise<T> {
    return this.sendWithBody<TBody, T>("PUT", url, body, validate, options);
  }

  async patch<TBody, T>(
    url: string,
    body: TBody,
    validate?: ResponseGuard<T>,
    options?: RequestOptions,
  ): Promise<T> {
    return this.sendWithBody<TBody, T>("PATCH", url, body, validate, options);
  }

  async delete(url: string, options?: RequestOptions): Promise<void> {
    const { res, raw } = await this.request(
      this.resolveUrl(url),
      {
        method: "DELETE",
        headers: { ...options?.headers, Accept: "application/json" },
        cache: "no-store",
      },
      options,
    );

    if (!res.ok) {
      throw this.toHttpError(res, raw);
    }
  }

  private async sendWithBody<TBody, T>(
    method: "POST" | "PUT" | "PATCH",
    url: string,
    body: TBody,
    validate?: ResponseGuard<T>,
    options?: RequestOptions,
  ): Promise<T> {
    const { res, raw } = await this.request(
      this.resolveUrl(url),
      {
        method,
        headers: {
          // Caller headers first: the body is always JSON.stringify'd, so Accept and Content-Type
          // describe what this client actually sends and a caller must not be able to contradict
          // them — a "text/plain" override would ship a JSON body the API then refuses as 415.
          ...options?.headers,
          Accept: "application/json",
          "Content-Type": "application/json",
        },
        cache: "no-store",
        body: JSON.stringify(body),
      },
      options,
    );

    if (!res.ok) {
      throw this.toHttpError(res, raw);
    }

    return this.parseBody<T>(res, raw, url, validate);
  }

  // why: a 2xx whose body drifted from the expected envelope must surface as a
  // typed boundary error here, not as a TypeError deep inside a mapper (e.g. a
  // stale browser bundle fetching a newer, reshaped API response).
  private parseBody<T>(res: Response, raw: string, url: string, validate?: ResponseGuard<T>): T {
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

  // why: a request the client gave up on is not "could not reach the server" — the server may
  // well have received and applied it. Naming the budget in the detail is what keeps a triage
  // from reading a timeout as an outage. status 0 for the same reason transportError uses it:
  // there is no response, so there is no status and no X-Correlation-Id to join.
  private timeoutError(timeoutMs: number): HttpError {
    return new HttpError({
      type: REQUEST_TIMEOUT,
      title: "The server did not answer in time",
      status: 0,
      detail: `The request was given up on after ${timeoutMs}ms.`,
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

  private toHttpError(res: Response, raw: string): HttpError {
    const parsed = ((): unknown => {
      try {
        return JSON.parse(raw);
      } catch {
        return null;
      }
    })();
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
