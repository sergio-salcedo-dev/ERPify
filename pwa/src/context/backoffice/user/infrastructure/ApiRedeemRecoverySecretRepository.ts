import { inject, injectable } from "inversify";
import { API_ENDPOINTS } from "@/context/shared/http-client/infrastructure/ApiEndpoints";
import { HttpError } from "@/context/shared/http-client/domain/HttpError";
import { HttpStatus } from "@/context/shared/http-client/domain/HttpStatus";
import type { HttpClient } from "@/context/shared/http-client/domain/HttpClient";
import { uuidV7 } from "@/context/shared/uuid/infrastructure/uuidV7";
import type { RedeemRecoverySecretRepository } from "../domain/RedeemRecoverySecretRepository";
import {
  RedeemRecoverySecretOutcomeKind,
  RedeemRecoverySecretProblemType,
  type RedeemRecoverySecretOutcome,
} from "../domain/RedeemRecoverySecretOutcome";

/** The wire body the redeem endpoint expects. */
interface RedeemRecoverySecretRequest {
  secret: string;
}

/**
 * Maps the recovery-redeem HTTP contract to a {@link RedeemRecoverySecretOutcome}:
 *
 *  - 204 → redeemed (httpOnly session cookie set server-side).
 *  - type `invalid-token` → invalid-secret. This is the whole failure space of the secret,
 *    collapsed on purpose by the API; routed by type, never by the status alone.
 *  - 403 + type `account-suspended` / `account-deactivated` → the matching wall. A generic
 *    `forbidden` 403 is an origin/CSRF rejection rather than an account state, so it falls
 *    through and is rethrown — otherwise a stripped `Origin` would render the terminal
 *    "account is not active" wall over a request that was merely refused at the door.
 *  - anything else — an unavailable session store (503), a transport fault — is not a redeem
 *    outcome and is rethrown for the neutral retryable error.
 *
 * The `X-CSRF-Token` header carries a client-generated, single-use CSRF nonce: the backend
 * only length-checks it, so a v7 UUID satisfies the contract while keeping every client-side
 * id on the one canonical generator. It travels as a header rather than a body field because
 * the endpoint rejects any body member its request payload does not declare — and a custom
 * header cannot be forged by a cross-origin form post without clearing a CORS preflight.
 */
@injectable()
export class ApiRedeemRecoverySecretRepository implements RedeemRecoverySecretRepository {
  constructor(@inject("HttpClient") private readonly httpClient: HttpClient) {}

  async redeem(secret: string): Promise<RedeemRecoverySecretOutcome> {
    const request: RedeemRecoverySecretRequest = { secret };
    try {
      await this.httpClient.post<RedeemRecoverySecretRequest, void>(
        API_ENDPOINTS.BACKOFFICE.RECOVERY_REDEEM,
        request,
        undefined,
        { headers: { "X-CSRF-Token": uuidV7() } },
      );
      return { kind: RedeemRecoverySecretOutcomeKind.REDEEMED };
    } catch (error) {
      if (error instanceof HttpError) {
        return this.toOutcome(error);
      }
      throw error;
    }
  }

  private toOutcome(error: HttpError): RedeemRecoverySecretOutcome {
    const { problem } = error;
    if (problem.type === RedeemRecoverySecretProblemType.INVALID_TOKEN) {
      return { kind: RedeemRecoverySecretOutcomeKind.INVALID_SECRET };
    }
    if (problem.status === HttpStatus.FORBIDDEN) {
      if (problem.type === RedeemRecoverySecretProblemType.ACCOUNT_SUSPENDED) {
        return { kind: RedeemRecoverySecretOutcomeKind.SUSPENDED };
      }
      if (problem.type === RedeemRecoverySecretProblemType.ACCOUNT_DEACTIVATED) {
        return { kind: RedeemRecoverySecretOutcomeKind.DEACTIVATED };
      }
    }
    throw error;
  }
}
