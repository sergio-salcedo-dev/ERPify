/** Runtime shape check applied to a 2xx JSON body at the HTTP boundary. */
export type ResponseGuard<T> = (body: unknown) => body is T;

/** `ProblemDetails.type` minted when a 2xx body fails its {@link ResponseGuard}. */
export const MALFORMED_RESPONSE_ENVELOPE = "malformed-response-envelope";

export interface HttpClient {
  get<T>(url: string, validate?: ResponseGuard<T>): Promise<T>;
  post<TBody, T>(url: string, body: TBody, validate?: ResponseGuard<T>): Promise<T>;
  put<TBody, T>(url: string, body: TBody, validate?: ResponseGuard<T>): Promise<T>;
  patch<TBody, T>(url: string, body: TBody, validate?: ResponseGuard<T>): Promise<T>;
  delete(url: string): Promise<void>;
}
