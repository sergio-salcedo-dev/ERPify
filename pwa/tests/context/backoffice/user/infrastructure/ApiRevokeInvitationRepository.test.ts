import { describe, expect, it, vi } from "vitest";
import { ApiRevokeInvitationRepository } from "@/context/backoffice/user/infrastructure/ApiRevokeInvitationRepository";
import { HttpError } from "@/context/shared/http-client/domain/HttpError";
import { HttpStatus } from "@/context/shared/http-client/domain/HttpStatus";
import type { HttpClient } from "@/context/shared/http-client/domain/HttpClient";
import { API_ENDPOINTS } from "@/context/shared/http-client/infrastructure/ApiEndpoints";

// Built inline so each `vi.fn()` is contextually typed to the matching (generic)
// HttpClient method; recover the spy for assertions via `vi.mocked(client.delete)`.
function httpClient(): HttpClient {
  return { get: vi.fn(), post: vi.fn(), put: vi.fn(), patch: vi.fn(), delete: vi.fn() };
}

const USER_ID = "0190a1b2-c3d4-7e5f-8a9b-0c1d2e3f4a5c";

describe("ApiRevokeInvitationRepository", () => {
  it("DELETEs the invitation sub-resource of the user", async () => {
    const client = httpClient();
    vi.mocked(client.delete).mockResolvedValue(undefined);

    await new ApiRevokeInvitationRepository(client).revoke(USER_ID);

    expect(client.delete).toHaveBeenCalledWith(`/api/v1/backoffice/users/${USER_ID}/invitation`);
    expect(client.delete).toHaveBeenCalledWith(
      API_ENDPOINTS.BACKOFFICE.USERS.REVOKE_INVITATION(USER_ID),
    );
  });

  it("percent-encodes the id it interpolates into the path", async () => {
    const client = httpClient();
    vi.mocked(client.delete).mockResolvedValue(undefined);

    await new ApiRevokeInvitationRepository(client).revoke("../../admin");

    expect(client.delete).toHaveBeenCalledWith(
      "/api/v1/backoffice/users/..%2F..%2Fadmin/invitation",
    );
  });

  it("propagates a typed HttpError (404 when nothing is revocable) without swallowing it", async () => {
    const client = httpClient();
    vi.mocked(client.delete).mockRejectedValue(
      new HttpError({
        type: "revocable-invitation-not-found",
        title: "No pending invitation was found for the given user.",
        status: HttpStatus.NOT_FOUND,
        instance: "0190a1b2-c3d4-7e5f-8a9b-0c1d2e3f4a5b",
        "correlation-id": "0190a1b2-c3d4-7e5f-8a9b-0c1d2e3f4a6c",
      }),
    );

    await expect(new ApiRevokeInvitationRepository(client).revoke(USER_ID)).rejects.toBeInstanceOf(
      HttpError,
    );
  });
});
