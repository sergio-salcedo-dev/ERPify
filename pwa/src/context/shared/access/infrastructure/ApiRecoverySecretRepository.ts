import { inject, injectable } from "inversify";
import { API_ENDPOINTS } from "@/context/shared/http-client/infrastructure/ApiEndpoints";
import type { HttpClient, ResponseGuard } from "@/context/shared/http-client/domain/HttpClient";
import type { MintedRecoverySecret, RecoverySecretStatus } from "../domain/RecoverySecret";
import type { RecoverySecretRepository } from "../domain/RecoverySecretRepository";

interface RecoverySecretStatusEnvelope {
  data: RecoverySecretStatus;
}

interface MintedRecoverySecretEnvelope {
  data: MintedRecoverySecret;
}

function envelopeData(body: unknown): Record<string, unknown> | null {
  if (typeof body !== "object" || body === null) return null;
  const { data } = body as { data?: unknown };
  if (typeof data !== "object" || data === null) return null;
  return data as Record<string, unknown>;
}

/**
 * The two instants are required exactly when a secret exists. Checking them against `exists`
 * rather than accepting either shape is what lets the surface state the expiry unconditionally:
 * a body claiming a secret without saying when it dies fails the guard and becomes a
 * `malformed-response-envelope`, instead of rendering an existing secret with a blank expiry.
 */
const isStatusEnvelope: ResponseGuard<RecoverySecretStatusEnvelope> = (
  body,
): body is RecoverySecretStatusEnvelope => {
  const data = envelopeData(body);
  if (data === null) return false;
  if (typeof data.exists !== "boolean") return false;
  return data.exists
    ? typeof data.mintedAt === "string" && typeof data.expiresAt === "string"
    : data.mintedAt === null && data.expiresAt === null;
};

const isMintedEnvelope: ResponseGuard<MintedRecoverySecretEnvelope> = (
  body,
): body is MintedRecoverySecretEnvelope => {
  const data = envelopeData(body);
  if (data === null) return false;
  return (
    typeof data.secret === "string" &&
    typeof data.mintedAt === "string" &&
    typeof data.expiresAt === "string"
  );
};

/** The wire body the mint endpoint expects. */
interface MintRecoverySecretRequest {
  currentPassword: string;
}

/** The wire body the revoke endpoint expects. */
interface RevokeRecoverySecretRequest {
  currentPassword: string;
}

/**
 * HTTP adapter over the signed-in identity's own recovery secret:
 *
 *  - `GET /me/recovery-secret` → `{ data: { exists, mintedAt, expiresAt } }`.
 *  - `POST /me/recovery-secret` → 201 `{ data: { secret, mintedAt, expiresAt } }`; the
 *    plaintext is returned once and is unrecoverable afterwards.
 *  - `POST /me/recovery-secret/revoke` → 204, carrying the current password. A verb with a
 *    body, because destroying the account's last way back in is proved with the credential
 *    rather than with the session that asks.
 *
 * No status code is interpreted here. A wrong password is a 403 `invalid-current-password`
 * and a second mint a 409 `recovery-secret-already-exists`; both reach the caller as the
 * transport's `HttpError`, which routes on the problem `type` — the same contract the
 * credential change follows, and the reason a 403 here is never mistaken for an expired
 * session.
 */
@injectable()
export class ApiRecoverySecretRepository implements RecoverySecretRepository {
  constructor(@inject("HttpClient") private readonly httpClient: HttpClient) {}

  async read(): Promise<RecoverySecretStatus> {
    const { data } = await this.httpClient.get(
      API_ENDPOINTS.IDENTITY.RECOVERY_SECRET,
      isStatusEnvelope,
    );
    return data;
  }

  async mint(currentPassword: string): Promise<MintedRecoverySecret> {
    const { data } = await this.httpClient.post<
      MintRecoverySecretRequest,
      MintedRecoverySecretEnvelope
    >(API_ENDPOINTS.IDENTITY.RECOVERY_SECRET, { currentPassword }, isMintedEnvelope);
    return data;
  }

  async revoke(currentPassword: string): Promise<void> {
    await this.httpClient.post<RevokeRecoverySecretRequest, void>(
      API_ENDPOINTS.IDENTITY.RECOVERY_SECRET_REVOKE,
      { currentPassword },
    );
  }
}
