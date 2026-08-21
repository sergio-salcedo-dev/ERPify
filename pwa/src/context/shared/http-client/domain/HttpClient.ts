/** Runtime shape check applied to a 2xx JSON body at the HTTP boundary. */
export type ResponseGuard<T> = (body: unknown) => body is T;

/** `ProblemDetails.type` minted when a 2xx body fails its {@link ResponseGuard}. */
export const MALFORMED_RESPONSE_ENVELOPE = "malformed-response-envelope";

/** `ProblemDetails.type` minted client-side when a request never reaches the server (offline / DNS / CORS). */
export const NETWORK_ERROR = "network-error";

/** `ProblemDetails.type` minted client-side when a request was given up on before it answered. */
export const REQUEST_TIMEOUT = "request-timeout";

/**
 * Per-call transport metadata. Not part of any application payload — this is how a caller
 * states what the *transport* should do, so a caller that needs a tighter bound than the
 * client's default does not have to own a timer of its own to enforce it.
 */
export interface RequestOptions {
  /**
   * `headers` carries per-request transport metadata that is not part of the application
   * payload — today the stateless CSRF token the accept-invitation and reset-password
   * endpoints read from `X-CSRF-Token`. It stays off the body so the request DTO keeps
   * modelling only the application contract, which the API enforces by rejecting undeclared
   * body members.
   */
  headers?: Record<string, string>;
  /**
   * Give up after this many milliseconds. Overrides the client's default for this call only.
   */
  timeoutMs?: number;
}

export interface HttpClient {
  get<T>(url: string, validate?: ResponseGuard<T>, options?: RequestOptions): Promise<T>;
  post<TBody, T>(
    url: string,
    body: TBody,
    validate?: ResponseGuard<T>,
    options?: RequestOptions,
  ): Promise<T>;
  put<TBody, T>(
    url: string,
    body: TBody,
    validate?: ResponseGuard<T>,
    options?: RequestOptions,
  ): Promise<T>;
  patch<TBody, T>(
    url: string,
    body: TBody,
    validate?: ResponseGuard<T>,
    options?: RequestOptions,
  ): Promise<T>;
  delete(url: string, options?: RequestOptions): Promise<void>;
}
