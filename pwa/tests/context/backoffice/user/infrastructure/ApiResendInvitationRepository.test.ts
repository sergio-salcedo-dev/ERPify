import { describe, expect, it, vi } from "vitest";
import { ApiResendInvitationRepository } from "@/context/backoffice/user/infrastructure/ApiResendInvitationRepository";
import { HttpError } from "@/context/shared/http-client/domain/HttpError";
import { HttpStatus } from "@/context/shared/http-client/domain/HttpStatus";
import type { HttpClient } from "@/context/shared/http-client/domain/HttpClient";

// Built inline so each `vi.fn()` is contextually typed to the matching (generic)
// HttpClient method; recover the spy for assertions via `vi.mocked(client.post)`.
function httpClient(): HttpClient {
  return { get: vi.fn(), post: vi.fn(), put: vi.fn(), patch: vi.fn(), delete: vi.fn() };
}

const USER_ID = "0190a1b2-c3d4-7e5f-8a9b-0c1d2e3f4a5c";

describe("ApiResendInvitationRepository", () => {
  it("POSTs no body to the invitation resend action of the user, once, and resolves to nothing", async () => {
    const client = httpClient();
    vi.mocked(client.post).mockResolvedValue(undefined);

    // The path is spelled out rather than rebuilt from `API_ENDPOINTS`: asserting that the adapter called the
    // same helper it calls pins nothing, while the literal fails the day the route moves under it.
    const result = await new ApiResendInvitationRepository(client).resend(USER_ID);

    expect(client.post).toHaveBeenCalledExactlyOnceWith(
      `/api/v1/backoffice/users/${USER_ID}/invitation/resend`,
      undefined,
    );
    // A resend is never the withdrawal's DELETE, and the 204 must surface as `undefined` — the new link is a
    // credential that travels only in the email, so no body exists for a caller to come to depend on.
    expect(client.delete).not.toHaveBeenCalled();
    expect(client.patch).not.toHaveBeenCalled();
    expect(client.put).not.toHaveBeenCalled();
    expect(client.get).not.toHaveBeenCalled();
    expect(result).toBeUndefined();
  });

  it("percent-encodes the id it interpolates into the path", async () => {
    const client = httpClient();
    vi.mocked(client.post).mockResolvedValue(undefined);

    await new ApiResendInvitationRepository(client).resend("../../admin");

    expect(client.post).toHaveBeenCalledWith(
      "/api/v1/backoffice/users/..%2F..%2Fadmin/invitation/resend",
      undefined,
    );
  });

  it("propagates a typed HttpError (503 when the email could not be sent) without swallowing it", async () => {
    const client = httpClient();
    vi.mocked(client.post).mockRejectedValue(
      new HttpError({
        type: "invitation-email-not-sent",
        title:
          "A new invitation link was issued, but the email could not be sent. The previous link no longer works; resend the invitation once email delivery is restored.",
        status: HttpStatus.SERVICE_UNAVAILABLE,
        instance: "0190a1b2-c3d4-7e5f-8a9b-0c1d2e3f4a5b",
        "correlation-id": "0190a1b2-c3d4-7e5f-8a9b-0c1d2e3f4a6c",
      }),
    );

    await expect(new ApiResendInvitationRepository(client).resend(USER_ID)).rejects.toBeInstanceOf(
      HttpError,
    );
  });
});
