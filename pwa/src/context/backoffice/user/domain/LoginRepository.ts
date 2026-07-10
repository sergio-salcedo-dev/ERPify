import type { LoginCredentials } from "./LoginCredentials";
import type { LoginOutcome } from "./LoginOutcome";

/**
 * Port for the session sign-in. The adapter owns the HTTP call and maps the
 * response to a typed {@link LoginOutcome}, so callers depend on this domain
 * contract (DIP) and never touch `fetch` / `HttpClient` / status codes.
 */
export interface LoginRepository {
  login(credentials: LoginCredentials): Promise<LoginOutcome>;
}
