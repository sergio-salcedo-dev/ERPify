import type { Identity } from "./Identity";

/** The credential change a signed-in identity performs on itself. */
export interface ChangePasswordCommand {
  currentPassword: string;
  newPassword: string;
}

/**
 * Port for the signed-in identity: resolving it from the gated `who-am-i`
 * endpoint and changing its own credential. The adapter owns the HTTP calls and
 * the 401 mapping, so the access layer depends on this domain contract (DIP) and
 * never touches `fetch` / status codes.
 */
export interface IdentityRepository {
  /** The signed-in identity, or `null` when there is no live session (401). */
  me(): Promise<Identity | null>;

  /**
   * Replace the signed-in identity's password, proving ownership with the
   * current one. Resolves on the 204; every rejection is the transport's
   * `HttpError`, so the caller reads the problem `type` (`invalid-current-password`,
   * `new-password-must-differ`) rather than a bespoke outcome union.
   */
  changePassword(command: ChangePasswordCommand): Promise<void>;
}
