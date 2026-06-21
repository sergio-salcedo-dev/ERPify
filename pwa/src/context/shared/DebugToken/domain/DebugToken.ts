/**
 * The per-request Symfony profiler handle, read from a `/api/*` response.
 * `token` indexes the profile (`/_wdt/{token}`, `/_profiler/{token}`);
 * `profilerUrl` is the absolute profiler link Symfony emits, or `null` when the
 * response carried no `X-Debug-Token-Link` header.
 */
export interface DebugToken {
  readonly token: string;
  readonly profilerUrl: string | null;
}
