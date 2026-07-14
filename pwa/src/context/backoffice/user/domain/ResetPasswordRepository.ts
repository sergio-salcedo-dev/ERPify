import type { ResetPasswordCommand } from "./ResetPasswordCommand";
import type { ResetPasswordOutcome } from "./ResetPasswordOutcome";

/**
 * Port for resetting a password. The adapter owns the HTTP call — including the
 * stateless CSRF nonce it attaches — and maps the response to a typed
 * {@link ResetPasswordOutcome}, so callers depend on this domain contract (DIP)
 * and never touch `fetch` / `HttpClient` / status codes.
 */
export interface ResetPasswordRepository {
  reset(command: ResetPasswordCommand): Promise<ResetPasswordOutcome>;
}
