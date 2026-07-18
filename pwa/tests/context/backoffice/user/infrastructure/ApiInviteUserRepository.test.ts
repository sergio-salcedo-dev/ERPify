import { describe, expect, it, vi } from "vitest";
import { ApiInviteUserRepository } from "@/context/backoffice/user/infrastructure/ApiInviteUserRepository";
import { Role } from "@/context/shared/access/domain/Role";
import { HttpError } from "@/context/shared/http-client/domain/HttpError";
import { HttpStatus } from "@/context/shared/http-client/domain/HttpStatus";
import type { HttpClient } from "@/context/shared/http-client/domain/HttpClient";
import { API_ENDPOINTS } from "@/context/shared/http-client/infrastructure/ApiEndpoints";

// Built inline so each `vi.fn()` is contextually typed to the matching (generic)
// HttpClient method; recover the spy for assertions via `vi.mocked(client.post)`.
function httpClient(): HttpClient {
  return { get: vi.fn(), post: vi.fn(), put: vi.fn(), patch: vi.fn(), delete: vi.fn() };
}

describe("ApiInviteUserRepository", () => {
  it("POSTs the invitation alta to the create endpoint", async () => {
    const client = httpClient();
    vi.mocked(client.post).mockResolvedValue(undefined);
    const input = { email: "newbie@erpify.test", roles: [Role.EDITOR, Role.VIEWER] };

    await new ApiInviteUserRepository(client).invite(input);

    expect(client.post).toHaveBeenCalledWith(API_ENDPOINTS.BACKOFFICE.INVITATIONS.CREATE, input);
  });

  it("propagates a typed HttpError (e.g. a 403 for a non-admin) without swallowing it", async () => {
    const client = httpClient();
    vi.mocked(client.post).mockRejectedValue(
      new HttpError({
        type: "forbidden",
        title: "Access denied.",
        status: HttpStatus.FORBIDDEN,
        instance: "0190a1b2-c3d4-7e5f-8a9b-0c1d2e3f4a5b",
        "correlation-id": "0190a1b2-c3d4-7e5f-8a9b-0c1d2e3f4a6c",
      }),
    );

    await expect(
      new ApiInviteUserRepository(client).invite({ email: "a@b.com", roles: [Role.VIEWER] }),
    ).rejects.toBeInstanceOf(HttpError);
  });
});
