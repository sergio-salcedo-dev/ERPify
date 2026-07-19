import { describe, expect, it, vi } from "vitest";
import { ApiChangeUserRolesRepository } from "@/context/backoffice/user/infrastructure/ApiChangeUserRolesRepository";
import { UserStatus } from "@/context/shared/access/domain/UserStatus";
import { Role } from "@/context/shared/access/domain/Role";
import { HttpError } from "@/context/shared/http-client/domain/HttpError";
import { HttpStatus } from "@/context/shared/http-client/domain/HttpStatus";
import type { HttpClient } from "@/context/shared/http-client/domain/HttpClient";
import { API_ENDPOINTS } from "@/context/shared/http-client/infrastructure/ApiEndpoints";
import type { UserPrimitives } from "@/context/backoffice/user/domain/User";

const USER_ID = "0190a1b2-c3d4-7e5f-8a9b-0c1d2e3f4a5c";

// Built inline so each `vi.fn()` is contextually typed to the matching (generic)
// HttpClient method; recover the spy for assertions via `vi.mocked(client.patch)`.
function httpClient(): HttpClient {
  return { get: vi.fn(), post: vi.fn(), put: vi.fn(), patch: vi.fn(), delete: vi.fn() };
}

function userPrimitives(roles: Role[]): UserPrimitives {
  return {
    id: USER_ID,
    email: "mallory@erpify.test",
    status: UserStatus.ACTIVE,
    roles,
    createdAt: "2026-01-01T00:00:00+00:00",
    updatedAt: "2026-01-02T00:00:00+00:00",
  };
}

describe("ApiChangeUserRolesRepository", () => {
  it("PATCHes the complete role set and returns the re-granted user", async () => {
    const client = httpClient();
    vi.mocked(client.patch).mockResolvedValue({
      data: userPrimitives([Role.EDITOR, Role.AUDIT_READER]),
    });

    const user = await new ApiChangeUserRolesRepository(client).changeRoles(USER_ID, [
      Role.EDITOR,
      Role.AUDIT_READER,
    ]);

    expect(client.patch).toHaveBeenCalledWith(
      API_ENDPOINTS.BACKOFFICE.USERS.CHANGE_ROLES(USER_ID),
      { roles: [Role.EDITOR, Role.AUDIT_READER] },
      expect.any(Function),
    );
    expect(user.id).toBe(USER_ID);
    expect(user.roles).toEqual([Role.EDITOR, Role.AUDIT_READER]);
  });

  it("propagates a typed HttpError (e.g. a 409 last-admin guard) without swallowing it", async () => {
    const client = httpClient();
    vi.mocked(client.patch).mockRejectedValue(
      new HttpError({
        type: "last-active-administrator-protected",
        title: "Cannot demote the last active administrator.",
        status: HttpStatus.CONFLICT,
        instance: "0190a1b2-c3d4-7e5f-8a9b-0c1d2e3f4a5b",
        "correlation-id": "0190a1b2-c3d4-7e5f-8a9b-0c1d2e3f4a6c",
      }),
    );

    await expect(
      new ApiChangeUserRolesRepository(client).changeRoles(USER_ID, [Role.VIEWER]),
    ).rejects.toBeInstanceOf(HttpError);
  });
});
