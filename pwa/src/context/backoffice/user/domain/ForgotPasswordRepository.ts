import type { ForgotPasswordCommand } from "./ForgotPasswordCommand";

/**
 * Port for requesting a password-reset link. The adapter owns the HTTP call and
 * resolves for every non-transport response: the endpoint answers a uniform 202
 * whether or not the address matches an account, so the caller can render one
 * confirmation without ever learning whether the account exists (no enumeration).
 * A transport fault rejects, so the caller can surface a neutral retryable error.
 */
export interface ForgotPasswordRepository {
  request(command: ForgotPasswordCommand): Promise<void>;
}
