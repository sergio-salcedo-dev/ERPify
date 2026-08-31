import type { RedeemRecoverySecretOutcome } from "./RedeemRecoverySecretOutcome";

/**
 * Port for redeeming a recovery secret. The adapter owns the HTTP call — including the
 * stateless CSRF nonce it attaches — and maps the response to a typed
 * {@link RedeemRecoverySecretOutcome}, so callers depend on this domain contract (DIP) and
 * never touch `fetch` / `HttpClient` / status codes.
 *
 * The secret is a plain argument because it comes from a form field and goes nowhere else:
 * it is never parsed, split, stored, or rendered back.
 */
export interface RedeemRecoverySecretRepository {
  redeem(secret: string): Promise<RedeemRecoverySecretOutcome>;
}
