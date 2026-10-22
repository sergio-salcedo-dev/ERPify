import type { APIRequestContext } from "@playwright/test";

import { E2E_USER_PASSWORD } from "../constants";

const RECOVERY_SECRET_PATH = "/api/v1/me/recovery-secret";
const RECOVERY_SECRET_REVOKE_PATH = "/api/v1/me/recovery-secret/revoke";

export interface RecoverySecretStatus {
  exists: boolean;
  live: boolean;
}

/** The E2E administrator's own password, which mint and revoke each re-prove. */
const PROOF = {
  data: { currentPassword: E2E_USER_PASSWORD },
  headers: { "Content-Type": "application/json" },
};

export async function readRecoverySecret(api: APIRequestContext): Promise<RecoverySecretStatus> {
  const response = await api.get(RECOVERY_SECRET_PATH);
  if (!response.ok()) {
    throw new Error(`GET ${RECOVERY_SECRET_PATH} failed (${response.status()})`);
  }
  const { data } = (await response.json()) as { data: RecoverySecretStatus };
  return data;
}

/** Revokes whatever secret the caller holds, live or lapsed. */
export async function revokeRecoverySecret(api: APIRequestContext): Promise<void> {
  const response = await api.post(RECOVERY_SECRET_REVOKE_PATH, PROOF);
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
 * The minted plaintext is discarded unread — nothing in the suite redeems it.
 */
export async function ensureLiveRecoverySecret(api: APIRequestContext): Promise<void> {
  const before = await readRecoverySecret(api);
  if (before.live) return;

  if (before.exists) await revokeRecoverySecret(api);

  const mint = await api.post(RECOVERY_SECRET_PATH, PROOF);
  if (mint.status() !== 201) {
    throw new Error(`minting the recovery secret failed (${mint.status()})`);
  }

  if (!(await readRecoverySecret(api)).live) {
    throw new Error("the E2E administrator still holds no live recovery secret");
  }
}
