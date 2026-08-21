import { injectable } from "inversify";
import { API_ENDPOINTS } from "./ApiEndpoints";
import type { HttpClient, RequestOptions, ResponseGuard } from "../domain/HttpClient";

@injectable()
export class MockHttpClient implements HttpClient {
  // why: the mock returns surface-specific fake bodies, so it deliberately
  // ignores response guards — enforcing real envelopes is FetchHttpClient's job.
  async get<T>(url: string, _validate?: ResponseGuard<T>, _options?: RequestOptions): Promise<T> {
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

  async post<TBody, T>(
    _url: string,
    _body: TBody,
    _validate?: ResponseGuard<T>,
    _options?: RequestOptions,
  ): Promise<T> {
    return {} as T;
  }

  async put<TBody, T>(
    _url: string,
    _body: TBody,
    _validate?: ResponseGuard<T>,
    _options?: RequestOptions,
  ): Promise<T> {
    return {} as T;
  }

  async patch<TBody, T>(
    _url: string,
    _body: TBody,
    _validate?: ResponseGuard<T>,
    _options?: RequestOptions,
  ): Promise<T> {
    return {} as T;
  }

  async delete(_url: string, _options?: RequestOptions): Promise<void> {
    return;
  }
}
