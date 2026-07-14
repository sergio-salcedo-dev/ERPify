import { inject, injectable } from "inversify";
import { API_ENDPOINTS } from "@/context/shared/http-client/infrastructure/ApiEndpoints";
import { HttpError } from "@/context/shared/http-client/domain/HttpError";
import { HttpStatus } from "@/context/shared/http-client/domain/HttpStatus";
import type { HttpClient } from "@/context/shared/http-client/domain/HttpClient";
import type { LoginCredentials } from "../domain/LoginCredentials";
import type { LoginRepository } from "../domain/LoginRepository";
import { LoginOutcomeKind, LoginProblemType, type LoginOutcome } from "../domain/LoginOutcome";

/**
 * Maps the session sign-in HTTP contract to a {@link LoginOutcome}:
 *
 *  - 204 → authenticated (httpOnly cookie set server-side).
 *  - 403 + type `account-suspended` → suspended.
 *  - 403 + type `account-locked` → locked.
 *  - 403 + type `account-deactivated` → deactivated.
 *  - 403 + type `forbidden` → rethrow: a generic origin/CSRF rejection is not a
 *    login outcome, so the form surfaces it as a generic retryable error rather
 *    than mislabelling it as bad credentials (or mis-rendering the deactivated
 *    wall — that wall is keyed on the specific `account-deactivated` type).
 *  - 503 → request-failed (retryable — credentials were accepted, but finalising
 *    the session hit a transient store fault; not a credentials problem).
 *  - 401 / any other HTTP error → invalid-credentials (kept indistinguishable
 *    for neutrality — wrong password, unknown email and invited all collapse).
 *
 * Routing is on `problem.type`, never the status alone: several 403s carry
 * different meaning. A generic `forbidden` 403 and a non-HTTP failure
 * (network/transport) are not login outcomes, so they propagate.
 */
@injectable()
export class ApiLoginRepository implements LoginRepository {
  constructor(@inject("HttpClient") private readonly httpClient: HttpClient) {}

  async login(credentials: LoginCredentials): Promise<LoginOutcome> {
    try {
      await this.httpClient.post<LoginCredentials, void>(
        API_ENDPOINTS.BACKOFFICE.LOGIN,
        credentials,
      );
      return { kind: LoginOutcomeKind.AUTHENTICATED };
    } catch (error) {
      if (error instanceof HttpError) {
        return this.toOutcome(error);
      }
      throw error;
    }
  }

  private toOutcome(error: HttpError): LoginOutcome {
    const { problem } = error;
    if (problem.status === HttpStatus.FORBIDDEN) {
      if (problem.type === LoginProblemType.ACCOUNT_SUSPENDED) {
        return { kind: LoginOutcomeKind.SUSPENDED };
      }
      if (problem.type === LoginProblemType.ACCOUNT_LOCKED) {
        return { kind: LoginOutcomeKind.LOCKED };
      }
      if (problem.type === LoginProblemType.ACCOUNT_DEACTIVATED) {
        return { kind: LoginOutcomeKind.DEACTIVATED };
      }
      if (problem.type === LoginProblemType.FORBIDDEN) {
        throw error;
      }
    }
    if (problem.status === HttpStatus.SERVICE_UNAVAILABLE) {
      return { kind: LoginOutcomeKind.REQUEST_FAILED };
    }
    return { kind: LoginOutcomeKind.INVALID_CREDENTIALS };
  }
}
