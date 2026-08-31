import type { MintedRecoverySecret, RecoverySecretStatus } from "./RecoverySecret";

/**
 * Port for the signed-in identity's own recovery secret — the second credential it mints so a
 * lockout is recoverable. A sibling of the session registry rather than a member of
 * `IdentityRepository`: reading who you are and minting a standby credential are separate
 * capabilities, and a consumer of one has no business holding the other.
 *
 * The adapter owns the HTTP calls and the envelope; every rejection surfaces as the
 * transport's `HttpError`, so callers read the problem `type` and never a status code.
 */
export interface RecoverySecretRepository {
  /** Whether a secret exists and, if so, when it was minted and when it expires. */
  read(): Promise<RecoverySecretStatus>;

  /**
   * Mint the account's secret, proving ownership with the current password, and return the
   * plaintext — the only time it is ever returned. Rejects with `invalid-current-password`,
   * `recovery-secret-already-exists` (an account holds at most one), or `rate-limited`.
   */
  mint(currentPassword: string): Promise<MintedRecoverySecret>;

  /**
   * Destroy the account's secret, proving ownership with the current password: a session on
   * its own may not remove the account's last way back in. Irreversible — nothing recovers the
   * destroyed secret, only a fresh mint replaces it. Rejects with `invalid-current-password`
   * or `rate-limited`.
   */
  revoke(currentPassword: string): Promise<void>;
}
