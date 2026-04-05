import { injectable } from "inversify";
import { ApiRoutes } from "../ApiRoutes";

export interface HttpClient {
  get<T>(url: string): Promise<T>;
}

function trimBase(url: string): string {
  return url.replace(/\/$/, "");
}

function browserApiBase(): string {
  const v = process.env.NEXT_PUBLIC_SYMFONY_API_BASE_URL?.trim();
  return trimBase(v || "https://localhost");
}

function serverApiBase(): string {
  const internal = process.env.SYMFONY_INTERNAL_URL?.trim();
  if (internal) {
    return trimBase(internal);
  }
  return browserApiBase();
}

@injectable()
export class MockHttpClient implements HttpClient {
  async get<T>(url: string): Promise<T> {
    return new Promise((resolve) => {
      setTimeout(() => {
        if (url.includes(ApiRoutes.v1.frontoffice.health)) {
          resolve({
            status: "ok",
            service: "Front office",
            datetime: new Date().toISOString(),
          } as T);
        } else if (url.includes(ApiRoutes.v1.backoffice.health)) {
          resolve({
            status: "ok",
            service: "Back office",
            datetime: new Date().toISOString(),
          } as T);
        } else {
          resolve({ status: "ok", service: "Unknown", datetime: new Date().toISOString() } as T);
        }
      }, 500);
    });
  }
}

@injectable()
export class FetchHttpClient implements HttpClient {
  private readonly baseUrl: string;

  constructor() {
    this.baseUrl = typeof window !== "undefined" ? browserApiBase() : serverApiBase();
  }

  async get<T>(url: string): Promise<T> {
    const path = url.startsWith("/") ? url : `/${url}`;
    const fullUrl = `${this.baseUrl}${path}`;
    const res = await fetch(fullUrl, {
      headers: { Accept: "application/json" },
      cache: "no-store",
    });

    if (!res.ok) {
      throw new Error(`HTTP ${res.status}`);
    }

    return (await res.json()) as T;
  }
}
