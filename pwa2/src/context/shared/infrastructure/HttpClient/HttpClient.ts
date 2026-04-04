import { injectable } from "inversify";

export interface HttpClient {
  get<T>(url: string): Promise<T>;
}

@injectable()
export class MockHttpClient implements HttpClient {
  async get<T>(url: string): Promise<T> {
    return new Promise((resolve) => {
      setTimeout(() => {
        // Mock response based on URL or generic
        if (url.includes("frontoffice")) {
          resolve({
            status: "ok",
            service: "Front Office",
            datetime: new Date().toISOString(),
          } as T);
        } else if (url.includes("backoffice")) {
          resolve({
            status: "ok",
            service: "Back Office",
            datetime: new Date().toISOString(),
          } as T);
        } else {
          resolve({ status: "ok", service: "Unknown", datetime: new Date().toISOString() } as T);
        }
      }, 500);
    });
  }
}
