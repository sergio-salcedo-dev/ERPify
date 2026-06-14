import { inject, injectable } from "inversify";
import { isProblemDetails, type ProblemDetails } from "../../domain/ProblemDetails";
import { API_ENDPOINTS } from "../api/ApiEndpoints";
import { HttpError } from "./HttpError";
import type { DebugTokenObserver } from "../../domain/DebugToken/DebugTokenObserver";
import { NoopDebugTokenObserver } from "../DebugToken/NoopDebugTokenObserver";
import { uuidV7 } from "@/lib/uuidV7";

/** Runtime shape check applied to a 2xx JSON body at the HTTP boundary. */
export type ResponseGuard<T> = (body: unknown) => body is T;

/** `ProblemDetails.type` minted when a 2xx body fails its {@link ResponseGuard}. */
export const MALFORMED_RESPONSE_ENVELOPE = "malformed-response-envelope";

export interface HttpClient {
  get<T>(url: string, validate?: ResponseGuard<T>): Promise<T>;
  post<TBody, T>(url: string, body: TBody, validate?: ResponseGuard<T>): Promise<T>;
  put<TBody, T>(url: string, body: TBody, validate?: ResponseGuard<T>): Promise<T>;
  delete(url: string): Promise<void>;
}

function trimBase(url: string): string {
  return url.replace(/\/$/, "");
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
export class MockHttpClient implements HttpClient {
  // why: the mock returns surface-specific fake bodies, so it deliberately
  // ignores response guards — enforcing real envelopes is FetchHttpClient's job.
  async get<T>(url: string, _validate?: ResponseGuard<T>): Promise<T> {
    return new Promise((resolve) => {
      setTimeout(() => {
        if (url.includes(API_ENDPOINTS.BACKOFFICE.HEALTH_DATABASE)) {
          // why: matched before BACKOFFICE.HEALTH — the database path is a
          // superstring of `/health`, so the broader branch would shadow it.
          resolve({
            data: {
              status: "ok",
              service: "Database",
              datetime: new Date().toISOString(),
            },
          } as T);
        } else if (url.includes(API_ENDPOINTS.FRONTOFFICE.HEALTH)) {
          resolve({
            data: {
              status: "ok",
              service: "Front office",
              datetime: new Date().toISOString(),
            },
          } as T);
        } else if (url.includes(API_ENDPOINTS.BACKOFFICE.HEALTH)) {
          resolve({
            data: {
              status: "ok",
              service: "Back office",
              datetime: new Date().toISOString(),
            },
          } as T);
        } else {
          resolve({
            data: { status: "ok", service: "Unknown", datetime: new Date().toISOString() },
          } as T);
        }
      }, 500);
    });
  }

  async post<TBody, T>(_url: string, _body: TBody, _validate?: ResponseGuard<T>): Promise<T> {
    return {} as T;
  }

  async put<TBody, T>(_url: string, _body: TBody, _validate?: ResponseGuard<T>): Promise<T> {
    return {} as T;
  }

  async delete(_url: string): Promise<void> {
    return;
  }
}

@injectable()
export class FetchHttpClient implements HttpClient {
  // Optional + defaulted so the ~20 direct `new FetchHttpClient()` call sites in
  // the unit suite keep working; the container always binds a real observer, so
  // production/dev resolution never hits the default.
  constructor(
    @inject("DebugTokenObserver")
    private readonly debugTokens: DebugTokenObserver = new NoopDebugTokenObserver(),
  ) {}

  // Single fetch chokepoint: every request reads the Symfony profiler token off
  // the response (success and error paths share this) and publishes it for the
  // dev-only toolbar. No-op in prod (header absent + inert observer).
  private async request(input: string, init: RequestInit): Promise<Response> {
    const res = await fetch(input, init);
    const token = res.headers.get("X-Debug-Token");
    if (token) {
      this.debugTokens.publish({ token, profilerUrl: res.headers.get("X-Debug-Token-Link") });
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

  async post<TBody, T>(url: string, body: TBody, validate?: ResponseGuard<T>): Promise<T> {
    return this.sendWithBody<TBody, T>("POST", url, body, validate);
  }

  async put<TBody, T>(url: string, body: TBody, validate?: ResponseGuard<T>): Promise<T> {
    return this.sendWithBody<TBody, T>("PUT", url, body, validate);
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
    method: "POST" | "PUT",
    url: string,
    body: TBody,
    validate?: ResponseGuard<T>,
  ): Promise<T> {
    const res = await this.request(this.resolveUrl(url), {
      method,
      headers: {
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
