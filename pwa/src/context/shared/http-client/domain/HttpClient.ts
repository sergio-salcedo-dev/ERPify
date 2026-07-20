/** Runtime shape check applied to a 2xx JSON body at the HTTP boundary. */
export type ResponseGuard<T> = (body: unknown) => body is T;

/** `ProblemDetails.type` minted when a 2xx body fails its {@link ResponseGuard}. */
export const MALFORMED_RESPONSE_ENVELOPE = "malformed-response-envelope";

/** `ProblemDetails.type` minted client-side when a request never reaches the server (offline / DNS / CORS). */
export const NETWORK_ERROR = "network-error";

export interface HttpClient {
  get<T>(url: string, validate?: ResponseGuard<T>): Promise<T>;
  /**
   * `headers` carries per-request transport metadata that is not part of the application payload —
   * today the stateless CSRF token the accept-invitation and reset-password endpoints read from
   * `X-CSRF-Token`. It stays off the body so the request DTO keeps modelling only the application
   * contract, which the API enforces by rejecting undeclared body members.
   */
  post<TBody, T>(
    url: string,
    body: TBody,
    validate?: ResponseGuard<T>,
    headers?: Record<string, string>,
  ): Promise<T>;
  put<TBody, T>(url: string, body: TBody, validate?: ResponseGuard<T>): Promise<T>;
  patch<TBody, T>(url: string, body: TBody, validate?: ResponseGuard<T>): Promise<T>;
  delete(url: string): Promise<void>;
}
