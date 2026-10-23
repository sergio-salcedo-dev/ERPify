import type { APIRequestContext } from "@playwright/test";

import { E2E_USER_PASSWORD } from "../constants";

const RECOVERY_SECRET_PATH = "/api/v1/me/recovery-secret";
const RECOVERY_SECRET_REVOKE_PATH = "/api/v1/me/recovery-secret/revoke";

export interface RecoverySecretStatus {
  exists: boolean;
  live: boolean;
}

/** The body mint and revoke each re-prove the caller's password with. */
function proof(currentPassword: string) {
  return {
    data: { currentPassword },
    headers: { "Content-Type": "application/json" },
  };
}

export async function readRecoverySecret(api: APIRequestContext): Promise<RecoverySecretStatus> {
  const response = await api.get(RECOVERY_SECRET_PATH);
  if (!response.ok()) {
    throw new Error(`GET ${RECOVERY_SECRET_PATH} failed (${response.status()})`);
  }
  const { data } = (await response.json()) as { data: RecoverySecretStatus };
  return data;
}

/** Revokes whatever secret the caller holds, live or lapsed. */
export async function revokeRecoverySecret(
  api: APIRequestContext,
  currentPassword: string = E2E_USER_PASSWORD,
): Promise<void> {
  const response = await api.post(RECOVERY_SECRET_REVOKE_PATH, proof(currentPassword));
  if (response.status() !== 204) {
    throw new Error(`revoking the recovery secret failed (${response.status()})`);
  }
}

/**
 * Leaves the caller holding a LIVE recovery secret, spending as little of the per-identity
 * password-proof budget (10 per 15 minutes, shared by mint and revoke) as the state allows.
 *
 * Read first and touch nothing when the secret is already live. A lapsed secret still occupies the
 * slot, so it is revoked before minting. The outcome is verified by reading `live` back, never
 * inferred from the mint's status: a 409 answers a lapsed row exactly as it answers a live one.
 * The minted plaintext is discarded unread: a caller that needs to redeem a secret mints its own
 * through {@link mintFreshRecoverySecret}.
 */
export async function ensureLiveRecoverySecret(api: APIRequestContext): Promise<void> {
  const before = await readRecoverySecret(api);
  if (before.live) return;

  if (before.exists) await revokeRecoverySecret(api);

  const mint = await api.post(RECOVERY_SECRET_PATH, proof(E2E_USER_PASSWORD));
  if (mint.status() !== 201) {
    throw new Error(`minting the recovery secret failed (${mint.status()})`);
  }

  if (!(await readRecoverySecret(api)).live) {
    throw new Error("the E2E administrator still holds no live recovery secret");
  }
}

/**
 * Mints a new recovery secret for the caller and returns its plaintext, which the 201 is the only
 * place to read: the API stores a hash and never shows it again.
 *
 * A secret already in the slot (left by a run that failed before redeeming) is revoked first, so a
 * healthy run spends one password proof and a run after a failure two. The plaintext is a
 * credential: keep it in a variable, never log it or attach it to the report.
 */
export async function mintFreshRecoverySecret(
  api: APIRequestContext,
  currentPassword: string,
): Promise<string> {
  if ((await readRecoverySecret(api)).exists) await revokeRecoverySecret(api, currentPassword);

  const mint = await api.post(RECOVERY_SECRET_PATH, proof(currentPassword));
  if (mint.status() !== 201) {
    throw new Error(`minting the recovery secret failed (${mint.status()})`);
  }
  const { data } = (await mint.json()) as { data: { secret: string } };
  return data.secret;
}
