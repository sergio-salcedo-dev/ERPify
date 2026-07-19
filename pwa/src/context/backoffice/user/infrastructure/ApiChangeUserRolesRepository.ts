import { inject, injectable } from "inversify";
import { API_ENDPOINTS } from "@/context/shared/http-client/infrastructure/ApiEndpoints";
import type { HttpClient } from "@/context/shared/http-client/domain/HttpClient";
import type { Role } from "@/context/shared/access/domain/Role";
import { User } from "../domain/User";
import type { ChangeUserRolesRepository } from "../domain/ChangeUserRolesRepository";
import { isUserSingleResponse } from "./ApiUserRepository";

/**
 * Live adapter for the role re-grant: PATCHes the complete `{roles}` set to the identity's roles endpoint and
 * revalidates the detail resource through the shared {@link isUserSingleResponse} guard. The `HttpClient` throws
 * a typed `HttpError` on any non-2xx (403 for a non-admin, 409 when the demotion would leave no active
 * administrator, 422 for an empty set or a role outside the vocabulary), which the control maps to its
 * persistent error surface.
 */
@injectable()
export class ApiChangeUserRolesRepository implements ChangeUserRolesRepository {
  constructor(@inject("HttpClient") private readonly httpClient: HttpClient) {}

  async changeRoles(id: string, roles: Role[]): Promise<User> {
    const response = await this.httpClient.patch(
      API_ENDPOINTS.BACKOFFICE.USERS.CHANGE_ROLES(id),
      { roles },
      isUserSingleResponse,
    );
    return User.fromPrimitives(response.data);
  }
}
