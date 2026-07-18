import { inject, injectable } from "inversify";
import { API_ENDPOINTS } from "@/context/shared/http-client/infrastructure/ApiEndpoints";
import type { HttpClient } from "@/context/shared/http-client/domain/HttpClient";
import type { UserStatus } from "@/context/shared/access/domain/UserStatus";
import { User } from "../domain/User";
import type { ChangeUserStatusRepository } from "../domain/ChangeUserStatusRepository";
import { isUserSingleResponse } from "./ApiUserRepository";

/**
 * Live adapter for the status transition: PATCHes `{status}` to the identity's status endpoint and revalidates
 * the detail resource through the shared {@link isUserSingleResponse} guard. The `HttpClient` throws a typed
 * `HttpError` on any non-2xx (403 for a non-admin, 409 for the last-admin guard or an illegal transition, 422
 * for a target outside `{SUSPENDED, DEACTIVATED}`), which the control maps to its persistent error surface.
 */
@injectable()
export class ApiChangeUserStatusRepository implements ChangeUserStatusRepository {
  constructor(@inject("HttpClient") private readonly httpClient: HttpClient) {}

  async changeStatus(id: string, status: UserStatus): Promise<User> {
    const response = await this.httpClient.patch(
      API_ENDPOINTS.BACKOFFICE.USERS.CHANGE_STATUS(id),
      { status },
      isUserSingleResponse,
    );
    return User.fromPrimitives(response.data);
  }
}
