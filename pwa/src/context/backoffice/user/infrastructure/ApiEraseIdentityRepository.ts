import { inject, injectable } from "inversify";
import { API_ENDPOINTS } from "@/context/shared/http-client/infrastructure/ApiEndpoints";
import type { HttpClient } from "@/context/shared/http-client/domain/HttpClient";
import type { EraseIdentityRepository } from "../domain/EraseIdentityRepository";

/**
 * Live adapter for the GDPR erasure: POSTs the caller's current password to the identity's `erase` action. The
 * `HttpClient` resolves a 204 to `void` and throws a typed `HttpError` on any non-2xx (403 for a non-admin or a
 * wrong password, 409 for the self-erasure refusal or for a subject still holding the administrator role, 404
 * for an unknown id, 429 for a spent proof budget), which the control maps to its persistent error surface —
 * it renders the RFC 9457 `title`, so a new refusal reason needs no client change.
 */
@injectable()
export class ApiEraseIdentityRepository implements EraseIdentityRepository {
  constructor(@inject("HttpClient") private readonly httpClient: HttpClient) {}

  async erase(id: string, currentPassword: string): Promise<void> {
    await this.httpClient.post<{ currentPassword: string }, void>(
      API_ENDPOINTS.BACKOFFICE.USERS.ERASE(id),
      { currentPassword },
    );
  }
}
