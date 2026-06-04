import { injectable } from "inversify";
import { isProblemDetails, type ProblemDetails } from "../../domain/ProblemDetails";
import { HttpStatus } from "../../domain/types/http";
import { API_ENDPOINTS } from "../api/ApiEndpoints";
import { HttpError } from "./HttpError";
import { uuidV7 } from "@/lib/uuidV7";

export interface HttpClient {
  get<T>(url: string): Promise<T>;
  post<TBody, T>(url: string, body: TBody): Promise<T>;
  put<TBody, T>(url: string, body: TBody): Promise<T>;
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
  // The server path cannot issue a relative request — there is no document
  // origin during SSR / route handlers. Keep an absolute fallback when neither
  // SYMFONY_INTERNAL_URL nor the public override is set.
  return browser || "https://localhost";
}

@injectable()
export class MockHttpClient implements HttpClient {
  async get<T>(url: string): Promise<T> {
    return new Promise((resolve) => {
      setTimeout(() => {
        if (url.includes(API_ENDPOINTS.FRONTOFFICE.HEALTH)) {
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

  async post<TBody, T>(_url: string, _body: TBody): Promise<T> {
    return {} as T;
  }

  async put<TBody, T>(_url: string, _body: TBody): Promise<T> {
    return {} as T;
  }

  async delete(_url: string): Promise<void> {
    return;
  }
}

@injectable()
export class FetchHttpClient implements HttpClient {
  private readonly baseUrl: string;

  constructor() {
    this.baseUrl = globalThis.window === undefined ? serverApiBase() : browserApiBase();
  }

  async get<T>(url: string): Promise<T> {
    const res = await fetch(this.resolveUrl(url), {
      headers: { Accept: "application/json" },
      cache: "no-store",
    });

    if (!res.ok) {
      throw await this.toHttpError(res);
    }

    if (res.status === HttpStatus.NO_CONTENT) {
      return undefined as T;
    }

    return (await res.json()) as T;
  }

  async post<TBody, T>(url: string, body: TBody): Promise<T> {
    return this.sendWithBody<TBody, T>("POST", url, body);
  }

  async put<TBody, T>(url: string, body: TBody): Promise<T> {
    return this.sendWithBody<TBody, T>("PUT", url, body);
  }

  async delete(url: string): Promise<void> {
    const res = await fetch(this.resolveUrl(url), {
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
  ): Promise<T> {
    const res = await fetch(this.resolveUrl(url), {
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

    if (res.status === HttpStatus.NO_CONTENT) {
      return undefined as T;
    }

    return (await res.json()) as T;
  }

  private resolveUrl(url: string): string {
    const path = url.startsWith("/") ? url : `/${url}`;
    return `${this.baseUrl}${path}`;
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
          "correlation-id": res.headers.get("X-Correlation-Id") ?? uuidV7(),
        };
    return new HttpError(problem);
  }
}
