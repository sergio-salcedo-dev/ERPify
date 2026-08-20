import { inject, injectable } from "inversify";
import { API_ENDPOINTS } from "@/context/shared/http-client/infrastructure/ApiEndpoints";
import { HttpError } from "@/context/shared/http-client/domain/HttpError";
import { HttpStatus } from "@/context/shared/http-client/domain/HttpStatus";
import type { HttpClient } from "@/context/shared/http-client/domain/HttpClient";
import { uuidV7 } from "@/context/shared/uuid/infrastructure/uuidV7";
import type { ResetPasswordCommand } from "../domain/ResetPasswordCommand";
import type { ResetPasswordRepository } from "../domain/ResetPasswordRepository";
import {
  ResetPasswordOutcomeKind,
  ResetPasswordProblemType,
  type ResetPasswordOutcome,
} from "../domain/ResetPasswordOutcome";

/** The wire body the reset endpoint expects. */
interface ResetPasswordRequest {
  token: string;
  password: string;
}

/**
 * Maps the reset-password HTTP contract to a {@link ResetPasswordOutcome}:
 *
 *  - 204 → reset (httpOnly session cookie set server-side).
 *  - type `invalid-token` → invalid-link (dead token — routed by type, never the
 *    status alone).
 *  - 403 + type `account-suspended` → suspended.
 *  - 403 + type `account-deactivated` → deactivated.
 *  - type `validation-failed` → validation-error (the password failed policy).
 *  - anything else — a generic `forbidden` 403 (an origin/CSRF rejection) or a
 *    transport fault — is rethrown as a generic retryable error; it is not a
 *    reset outcome. The deactivated wall is keyed on the specific
 *    `account-deactivated` type, so a stripped-`Origin` request never mis-renders
 *    the terminal "account not active" wall.
 *
 * The `X-CSRF-Token` header carries a client-generated, single-use CSRF nonce
 * (defense-in-depth): the backend only length-checks it (>= 24), so a v7 UUID
 * (36 chars) satisfies the contract while keeping every client-side id on the one
 * canonical generator (never `crypto.randomUUID`; see `pwa/CLAUDE.md`). It travels
 * as a header, not a body field, because the endpoint rejects any body member its
 * request payload does not declare — and a custom header cannot be forged by a
 * cross-origin form post without clearing a CORS preflight.
 */
@injectable()
export class ApiResetPasswordRepository implements ResetPasswordRepository {
  constructor(@inject("HttpClient") private readonly httpClient: HttpClient) {}

  async reset(command: ResetPasswordCommand): Promise<ResetPasswordOutcome> {
    const request: ResetPasswordRequest = {
      token: command.token,
      password: command.password,
    };
    try {
      await this.httpClient.post<ResetPasswordRequest, void>(
        API_ENDPOINTS.BACKOFFICE.RESET_PASSWORD,
        request,
        undefined,
        { headers: { "X-CSRF-Token": uuidV7() } },
      );
      return { kind: ResetPasswordOutcomeKind.RESET };
    } catch (error) {
      if (error instanceof HttpError) {
        return this.toOutcome(error);
      }
      throw error;
    }
  }

  private toOutcome(error: HttpError): ResetPasswordOutcome {
    const { problem } = error;
    if (problem.type === ResetPasswordProblemType.INVALID_TOKEN) {
      return { kind: ResetPasswordOutcomeKind.INVALID_LINK };
    }
    if (problem.status === HttpStatus.FORBIDDEN) {
      if (problem.type === ResetPasswordProblemType.ACCOUNT_SUSPENDED) {
        return { kind: ResetPasswordOutcomeKind.SUSPENDED };
      }
      if (problem.type === ResetPasswordProblemType.ACCOUNT_DEACTIVATED) {
        return { kind: ResetPasswordOutcomeKind.DEACTIVATED };
      }
    }
    if (problem.type === ResetPasswordProblemType.VALIDATION_FAILED) {
      return {
        kind: ResetPasswordOutcomeKind.VALIDATION_ERROR,
        violations: problem.violations ?? [],
      };
    }
    throw error;
  }
}
