import { inject, injectable } from "inversify";
import { API_ENDPOINTS } from "@/context/shared/http-client/infrastructure/ApiEndpoints";
import type { HttpClient } from "@/context/shared/http-client/domain/HttpClient";
import type { EraseIdentityRepository } from "../domain/EraseIdentityRepository";

/**
 * Live adapter for the GDPR erasure: DELETEs the identity's detail URL. The `HttpClient` resolves a 204 to
 * `void` and throws a typed `HttpError` on any non-2xx (403 for a non-admin, 409 for the self-erasure refusal
 * or for a subject still holding the administrator role, 404 for an unknown id), which the control maps to its
 * persistent error surface — it renders the RFC 9457 `title`, so a new refusal reason needs no client change.
 */
@injectable()
export class ApiEraseIdentityRepository implements EraseIdentityRepository {
  constructor(@inject("HttpClient") private readonly httpClient: HttpClient) {}

  async erase(id: string): Promise<void> {
    await this.httpClient.delete(API_ENDPOINTS.BACKOFFICE.USERS.ERASE(id));
  }
}
