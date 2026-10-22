import type { RecoverySecretStatus } from "./RecoverySecret";

/**
 * Whether the signed-in account can get back in on its own if it is ever locked out, as the
 * back-office shell knows it.
 *
 * Three states, and `unknown` is not a spelling of "not live": a read still in flight, one that
 * failed, or an account the shell does not ask about all land there, and none of them is
 * grounds to tell an administrator they hold nothing. Only `not-live` warns, and it carries
 * WHY — no secret at all, or one that has lapsed — because the way out differs: create one, or
 * revoke the dead one first.
 *
 * It is a snapshot of the server's verdict at the last read or mutation, never recomputed from
 * `expiresAt` against this client's clock: a secret that lapses with the page open stays `live`
 * until the next read says otherwise.
 */
export type RecoveryReadiness =
  { kind: "unknown" } | { kind: "live" } | { kind: "not-live"; reason: "none" | "expired" };

export const UNKNOWN_RECOVERY_READINESS: RecoveryReadiness = { kind: "unknown" };

export function recoveryReadinessOf(status: RecoverySecretStatus): RecoveryReadiness {
  if (status.live) return { kind: "live" };
  return { kind: "not-live", reason: status.exists ? "expired" : "none" };
}
