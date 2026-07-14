import { inject, injectable } from "inversify";
import { API_ENDPOINTS } from "@/context/shared/http-client/infrastructure/ApiEndpoints";
import type { HttpClient } from "@/context/shared/http-client/domain/HttpClient";
import type { ForgotPasswordCommand } from "../domain/ForgotPasswordCommand";
import type { ForgotPasswordRepository } from "../domain/ForgotPasswordRepository";

/** The wire body the forgot-password endpoint expects. */
interface ForgotPasswordRequest {
  email: string;
}

/**
 * Posts the reset-link request to the forgot-password endpoint. The endpoint
 * answers a uniform 202 for every case (existing, unknown, any account state),
 * so a resolved call carries no signal about account existence — the caller
 * renders one confirmation regardless. No CSRF nonce: the route is same-origin
 * only (the browser sets `Origin`) and mutates no per-user state a forged
 * cross-site POST could weaponise. A transport fault propagates, so the caller
 * can surface a neutral, retryable error.
 */
@injectable()
export class ApiForgotPasswordRepository implements ForgotPasswordRepository {
  constructor(@inject("HttpClient") private readonly httpClient: HttpClient) {}

  async request(command: ForgotPasswordCommand): Promise<void> {
    const request: ForgotPasswordRequest = { email: command.email };
    await this.httpClient.post<ForgotPasswordRequest, void>(
      API_ENDPOINTS.BACKOFFICE.FORGOT_PASSWORD,
      request,
    );
  }
}
