import { inject, injectable } from "inversify";
import { API_ENDPOINTS } from "@/context/shared/http-client/infrastructure/ApiEndpoints";
import type { HttpClient } from "@/context/shared/http-client/domain/HttpClient";
import type { Role } from "@/context/shared/access/domain/Role";
import { User } from "../domain/User";
import type { ChangeUserRolesRepository } from "../domain/ChangeUserRolesRepository";
import { isUserSingleResponse } from "./ApiUserRepository";

/**
 * Live adapter for the role re-grant: PATCHes the complete `{roles}` set, with the caller's `currentPassword`
 * as proof, to the identity's roles endpoint and revalidates the detail resource through the shared
 * {@link isUserSingleResponse} guard. The password travels in the body only — never the URL, never a header.
 * The `HttpClient` throws a typed `HttpError` on any non-2xx (403 for a non-admin or `invalid-current-password`,
 * 409 when the demotion would leave no active administrator, 422 for an empty set or a role outside the
 * vocabulary, 429 once the shared proof budget is spent), which the control maps to its error surfaces.
 */
@injectable()
export class ApiChangeUserRolesRepository implements ChangeUserRolesRepository {
  constructor(@inject("HttpClient") private readonly httpClient: HttpClient) {}

  async changeRoles(id: string, roles: Role[], currentPassword: string): Promise<User> {
    const response = await this.httpClient.patch(
      API_ENDPOINTS.BACKOFFICE.USERS.CHANGE_ROLES(id),
      { roles, currentPassword },
      isUserSingleResponse,
    );
    return User.fromPrimitives(response.data);
  }
}
