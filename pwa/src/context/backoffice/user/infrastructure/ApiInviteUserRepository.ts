import { inject, injectable } from "inversify";
import { API_ENDPOINTS } from "@/context/shared/http-client/infrastructure/ApiEndpoints";
import type { HttpClient } from "@/context/shared/http-client/domain/HttpClient";
import type { InviteUserInput, InviteUserRepository } from "../domain/InviteUserRepository";

/**
 * Live adapter for the invitation alta: POSTs `{email, roles}` to the invitation create endpoint. The 201
 * carries no body, so there is nothing to validate — the `HttpClient` throws a typed `HttpError` on any non-2xx
 * (403 when not an ADMIN, 422 with per-field violations on a bad payload), which the form maps to its surface.
 */
@injectable()
export class ApiInviteUserRepository implements InviteUserRepository {
  constructor(@inject("HttpClient") private readonly httpClient: HttpClient) {}

  async invite(input: InviteUserInput): Promise<void> {
    await this.httpClient.post<InviteUserInput, void>(
      API_ENDPOINTS.BACKOFFICE.INVITATIONS.CREATE,
      input,
    );
  }
}
