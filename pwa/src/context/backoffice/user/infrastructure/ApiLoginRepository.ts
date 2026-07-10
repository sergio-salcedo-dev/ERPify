import { inject, injectable } from "inversify";
import { API_ENDPOINTS } from "@/context/shared/http-client/infrastructure/ApiEndpoints";
import { HttpError } from "@/context/shared/http-client/domain/HttpError";
import { HttpStatus } from "@/context/shared/http-client/domain/HttpStatus";
import type { HttpClient } from "@/context/shared/http-client/domain/HttpClient";
import type { ProblemDetails } from "@/context/shared/error/domain/ProblemDetails";
import type { LoginCredentials } from "../domain/LoginCredentials";
import type { LoginRepository } from "../domain/LoginRepository";
import { LoginOutcomeKind, LoginProblemType, type LoginOutcome } from "../domain/LoginOutcome";

/**
 * Maps the session sign-in HTTP contract to a {@link LoginOutcome}:
 *
 *  - 204 → authenticated (httpOnly cookie set server-side).
 *  - 403 + type `account-suspended` → suspended.
 *  - 403 + type `forbidden` → deactivated.
 *  - 401 / any other HTTP error → invalid-credentials (kept indistinguishable
 *    for neutrality — wrong password, unknown email and invited all collapse).
 *
 * Routing is on `problem.type`, never the status alone: two 403s carry different
 * meaning. A non-HTTP failure (network/transport) is not a login outcome, so it
 * propagates.
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
        return this.toOutcome(error.problem);
      }
      throw error;
    }
  }

  private toOutcome(problem: ProblemDetails): LoginOutcome {
    if (problem.status === HttpStatus.FORBIDDEN) {
      if (problem.type === LoginProblemType.ACCOUNT_SUSPENDED) {
        return { kind: LoginOutcomeKind.SUSPENDED };
      }
      if (problem.type === LoginProblemType.FORBIDDEN) {
        return { kind: LoginOutcomeKind.DEACTIVATED };
      }
    }
    return { kind: LoginOutcomeKind.INVALID_CREDENTIALS };
  }
}
