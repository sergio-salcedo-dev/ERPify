import { inject, injectable } from "inversify";
import { API_ENDPOINTS } from "@/context/shared/http-client/infrastructure/ApiEndpoints";
import type { HttpClient } from "@/context/shared/http-client/domain/HttpClient";
import type { RevokeInvitationRepository } from "../domain/RevokeInvitationRepository";

/**
 * Live adapter for the invitation withdrawal: DELETEs the identity's invitation URL. The `HttpClient` resolves
 * a 204 to `void` and throws a typed `HttpError` on any non-2xx (403 for a session without
 * `users.revokeInvitation`, 404 `revocable-invitation-not-found` when the user is already active, already
 * revoked or unknown), which the caller hands to its persistent error surface — that surface renders the RFC
 * 9457 `title`, so a new refusal reason needs no client change.
 *
 * The 204 carries no resource: the write moves both the invitation and the identity it provisioned to their
 * terminal states, and the caller re-reads the register rather than patching a row from a response body.
 */
@injectable()
export class ApiRevokeInvitationRepository implements RevokeInvitationRepository {
  constructor(@inject("HttpClient") private readonly httpClient: HttpClient) {}

  async revoke(userId: string): Promise<void> {
    await this.httpClient.delete(API_ENDPOINTS.BACKOFFICE.USERS.REVOKE_INVITATION(userId));
  }
}
