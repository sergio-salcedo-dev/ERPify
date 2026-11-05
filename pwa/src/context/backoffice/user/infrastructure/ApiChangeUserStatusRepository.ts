import { inject, injectable } from "inversify";
import { API_ENDPOINTS } from "@/context/shared/http-client/infrastructure/ApiEndpoints";
import type { HttpClient } from "@/context/shared/http-client/domain/HttpClient";
import type { UserStatus } from "@/context/shared/access/domain/UserStatus";
import { User } from "../domain/User";
import type { ChangeUserStatusRepository } from "../domain/ChangeUserStatusRepository";
import { isUserSingleResponse } from "./ApiUserRepository";

/**
 * Live adapter for the status transition: PATCHes `{status, currentPassword}` to the identity's status endpoint
 * and revalidates the detail resource through the shared {@link isUserSingleResponse} guard. The password
 * travels in the body only — never the URL, never a header. The `HttpClient` throws a typed `HttpError` on any
 * non-2xx (403 for a non-admin or `invalid-current-password`, 409 for the last-admin guard or an illegal
 * transition, 422 for a target outside `{SUSPENDED, DEACTIVATED}`, 429 once the shared proof budget is spent),
 * which the control maps to its error surfaces.
 */
@injectable()
export class ApiChangeUserStatusRepository implements ChangeUserStatusRepository {
  constructor(@inject("HttpClient") private readonly httpClient: HttpClient) {}

  async changeStatus(id: string, status: UserStatus, currentPassword: string): Promise<User> {
    const response = await this.httpClient.patch(
      API_ENDPOINTS.BACKOFFICE.USERS.CHANGE_STATUS(id),
      { status, currentPassword },
      isUserSingleResponse,
    );
    return User.fromPrimitives(response.data);
  }
}
