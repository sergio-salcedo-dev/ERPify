import { describe, expect, it, vi } from "vitest";
import { ApiEraseIdentityRepository } from "@/context/backoffice/user/infrastructure/ApiEraseIdentityRepository";
import { HttpError } from "@/context/shared/http-client/domain/HttpError";
import { HttpStatus } from "@/context/shared/http-client/domain/HttpStatus";
import type { HttpClient } from "@/context/shared/http-client/domain/HttpClient";
import { API_ENDPOINTS } from "@/context/shared/http-client/infrastructure/ApiEndpoints";

const USER_ID = "0190a1b2-c3d4-7e5f-8a9b-0c1d2e3f4a5c";
const PASSWORD = "the-callers-current-password";

// Built inline so each `vi.fn()` is contextually typed to the matching (generic)
// HttpClient method; recover the spy for assertions via `vi.mocked(client.post)`.
function httpClient(): HttpClient {
  return {
    get: vi.fn(),
    post: vi.fn(),
    put: vi.fn(),
    patch: vi.fn(),
    putRaw: vi.fn(),
    delete: vi.fn(),
  };
}

describe("ApiEraseIdentityRepository", () => {
  it("POSTs the caller's password to the identity's erase action and resolves to void", async () => {
    const client = httpClient();
    vi.mocked(client.post).mockResolvedValue(undefined);

    await expect(
      new ApiEraseIdentityRepository(client).erase(USER_ID, PASSWORD),
    ).resolves.toBeUndefined();

    expect(client.post).toHaveBeenCalledWith(API_ENDPOINTS.BACKOFFICE.USERS.ERASE(USER_ID), {
      currentPassword: PASSWORD,
    });
    expect(client.delete).not.toHaveBeenCalled();
  });

  it("propagates a typed HttpError (e.g. a 409 self-erasure refusal) without swallowing it", async () => {
    const client = httpClient();
    vi.mocked(client.post).mockRejectedValue(
      new HttpError({
        type: "self-erasure-forbidden",
        title: "An administrator cannot erase their own identity.",
        status: HttpStatus.CONFLICT,
        instance: "0190a1b2-c3d4-7e5f-8a9b-0c1d2e3f4a5b",
        "correlation-id": "0190a1b2-c3d4-7e5f-8a9b-0c1d2e3f4a6c",
      }),
    );

    await expect(
      new ApiEraseIdentityRepository(client).erase(USER_ID, PASSWORD),
    ).rejects.toBeInstanceOf(HttpError);
  });
});
