import { describe, expect, it, vi } from "vitest";
import { ApiIdentityRepository } from "@/context/shared/access/infrastructure/ApiIdentityRepository";
import { HttpError } from "@/context/shared/http-client/domain/HttpError";
import { HttpStatus } from "@/context/shared/http-client/domain/HttpStatus";
import { API_ENDPOINTS } from "@/context/shared/http-client/infrastructure/ApiEndpoints";
import { UserStatus } from "@/context/shared/access/domain/UserStatus";
import type { HttpClient } from "@/context/shared/http-client/domain/HttpClient";
import type { ProblemDetails } from "@/context/shared/error/domain/ProblemDetails";

function problem(status: number, type: string): ProblemDetails {
  return {
    type,
    title: type,
    status,
    instance: "0190ffff-aaaa-7bbb-8ccc-0d1e2f3a4b5c",
    "correlation-id": "0190ffff-aaaa-7bbb-8ccc-0d1e2f3a4b5d",
  };
}

function httpClientGetting(get: HttpClient["get"]): HttpClient {
  return { get, post: vi.fn(), put: vi.fn(), patch: vi.fn(), delete: vi.fn() };
}

describe("ApiIdentityRepository.me", () => {
  it("maps a 200 to an ACTIVE identity with backend roles verbatim and no permissions", async () => {
    const get = vi.fn().mockResolvedValue({
      data: {
        id: "0190aaaa-bbbb-7ccc-8ddd-0e1f2a3b4c5d",
        email: "a@b.com",
        roles: ["ADMIN", "AUDIT_READER"],
      },
    });

    const identity = await new ApiIdentityRepository(httpClientGetting(get)).me();

    expect(get).toHaveBeenCalledWith(API_ENDPOINTS.IDENTITY.ME, expect.any(Function));
    expect(identity).toEqual({
      id: "0190aaaa-bbbb-7ccc-8ddd-0e1f2a3b4c5d",
      email: "a@b.com",
      status: UserStatus.ACTIVE,
      roles: ["ADMIN", "AUDIT_READER"],
      permissions: [],
    });
  });

  it("returns null on a 401 (no live session)", async () => {
    const get = vi
      .fn()
      .mockRejectedValue(new HttpError(problem(HttpStatus.UNAUTHORIZED, "session-expired")));

    const identity = await new ApiIdentityRepository(httpClientGetting(get)).me();

    expect(identity).toBeNull();
  });

  it("rethrows a non-401 HTTP failure (unreachable server is not 'no session')", async () => {
    const boom = new HttpError(problem(HttpStatus.INTERNAL_SERVER_ERROR, "server-error"));
    const get = vi.fn().mockRejectedValue(boom);

    await expect(new ApiIdentityRepository(httpClientGetting(get)).me()).rejects.toBe(boom);
  });

  it("rethrows a non-HTTP transport failure", async () => {
    const boom = new Error("network down");
    const get = vi.fn().mockRejectedValue(boom);

    await expect(new ApiIdentityRepository(httpClientGetting(get)).me()).rejects.toBe(boom);
  });
});
