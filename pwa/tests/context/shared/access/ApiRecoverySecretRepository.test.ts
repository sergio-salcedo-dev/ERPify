import { describe, expect, it, vi } from "vitest";
import { ApiRecoverySecretRepository } from "@/context/shared/access/infrastructure/ApiRecoverySecretRepository";
import { API_ENDPOINTS } from "@/context/shared/http-client/infrastructure/ApiEndpoints";
import type { HttpClient } from "@/context/shared/http-client/domain/HttpClient";

function httpClient(overrides: Partial<HttpClient>): HttpClient {
  return {
    get: vi.fn(),
    post: vi.fn(),
    put: vi.fn(),
    patch: vi.fn(),
    delete: vi.fn(),
    ...overrides,
  };
}

describe("ApiRecoverySecretRepository", () => {
  it("revoke() posts the current password to the revoke path", async () => {
    const post = vi.fn().mockResolvedValue(undefined);
    const remove = vi.fn();

    await new ApiRecoverySecretRepository(httpClient({ post, delete: remove })).revoke("s3cr3t");

    // The credential rides in the body, which is the whole reason this is a POST on a path of
    // its own: a DELETE would have to carry the proof in the URL, where the access log keeps it.
    expect(post).toHaveBeenCalledWith(API_ENDPOINTS.IDENTITY.RECOVERY_SECRET_REVOKE, {
      currentPassword: "s3cr3t",
    });
    expect(remove).not.toHaveBeenCalled();
  });

  it("revoke() asks for no response guard, so an empty 204 is not a malformed envelope", async () => {
    const post = vi.fn().mockResolvedValue(undefined);

    await new ApiRecoverySecretRepository(httpClient({ post })).revoke("s3cr3t");

    expect(post.mock.calls[0]).toHaveLength(2);
  });

  it("revoke() never targets the path that reads and mints the secret", () => {
    expect(API_ENDPOINTS.IDENTITY.RECOVERY_SECRET_REVOKE).not.toBe(
      API_ENDPOINTS.IDENTITY.RECOVERY_SECRET,
    );
  });

  it("lets the transport's rejection through untouched, so the caller routes on the problem type", async () => {
    const rejection = new Error("403");
    const post = vi.fn().mockRejectedValue(rejection);

    await expect(
      new ApiRecoverySecretRepository(httpClient({ post })).revoke("wrong"),
    ).rejects.toBe(rejection);
  });
});
