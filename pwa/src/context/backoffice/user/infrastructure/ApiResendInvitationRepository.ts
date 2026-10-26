import { inject, injectable } from "inversify";
import { API_ENDPOINTS } from "@/context/shared/http-client/infrastructure/ApiEndpoints";
import type { HttpClient } from "@/context/shared/http-client/domain/HttpClient";
import type { ResendInvitationRepository } from "../domain/ResendInvitationRepository";

/**
 * Live adapter for the invitation resend: a bodyless POST to the identity's invitation `resend` action. The
 * `HttpClient` resolves the 204 to `void` and throws a typed `HttpError` on any non-2xx — 403 for a session
 * without `users.resendInvitation`, 404 `resendable-invitation-not-found` when the user is no longer pending,
 * 503 `invitation-email-not-sent` when the new link was minted but its email was refused — which the caller
 * hands to its persistent error surface. That surface renders the RFC 9457 `title`, so a new refusal reason
 * needs no client change.
 */
@injectable()
export class ApiResendInvitationRepository implements ResendInvitationRepository {
  constructor(@inject("HttpClient") private readonly httpClient: HttpClient) {}

  async resend(userId: string): Promise<void> {
    await this.httpClient.post<undefined, void>(
      API_ENDPOINTS.BACKOFFICE.USERS.RESEND_INVITATION(userId),
      undefined,
    );
  }
}
