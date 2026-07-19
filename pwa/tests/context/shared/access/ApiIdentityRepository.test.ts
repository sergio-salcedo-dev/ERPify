import { describe, expect, it, vi } from "vitest";
import { ApiIdentityRepository } from "@/context/shared/access/infrastructure/ApiIdentityRepository";
import { HttpError } from "@/context/shared/http-client/domain/HttpError";
import { HttpStatus } from "@/context/shared/http-client/domain/HttpStatus";
import { API_ENDPOINTS } from "@/context/shared/http-client/infrastructure/ApiEndpoints";
import { UserStatus } from "@/context/shared/access/domain/UserStatus";
import { MALFORMED_RESPONSE_ENVELOPE } from "@/context/shared/http-client/domain/HttpClient";
import type { HttpClient, ResponseGuard } from "@/context/shared/http-client/domain/HttpClient";
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

// Faithful to FetchHttpClient: run the guard against the body, throw a
// MALFORMED_RESPONSE_ENVELOPE HttpError (carrying the 2xx status) when it fails and
// return the body when it passes — so these tests exercise the real `isMeEnvelope`
// guard instead of a mock that hands back a canned value without ever calling it.
function guardingGet(body: unknown): HttpClient["get"] {
  async function get<T>(_url: string, validate?: ResponseGuard<T>): Promise<T> {
    if (validate && !validate(body)) {
      throw new HttpError(problem(HttpStatus.OK, MALFORMED_RESPONSE_ENVELOPE));
    }
    return body as T;
  }
  return get;
}

describe("ApiIdentityRepository.me", () => {
  it("maps a 200 to an ACTIVE identity with backend roles and permissions verbatim", async () => {
    const get = vi.fn().mockResolvedValue({
      data: {
        id: "0190aaaa-bbbb-7ccc-8ddd-0e1f2a3b4c5d",
        email: "a@b.com",
        roles: ["ADMIN", "AUDIT_READER"],
        permissions: ["users.read", "users.invite"],
      },
    });

    const identity = await new ApiIdentityRepository(httpClientGetting(get)).me();

    expect(get).toHaveBeenCalledWith(API_ENDPOINTS.IDENTITY.ME, expect.any(Function));
    expect(identity).toEqual({
      id: "0190aaaa-bbbb-7ccc-8ddd-0e1f2a3b4c5d",
      email: "a@b.com",
      status: UserStatus.ACTIVE,
      roles: ["ADMIN", "AUDIT_READER"],
      permissions: ["users.read", "users.invite"],
    });
  });

  // The API publishes permissions for resources this console does not gate on (banks, audit). They are
  // dropped rather than carried, so the session type keeps meaning what it says.
  it("drops permissions the client does not declare", async () => {
    const get = vi.fn().mockResolvedValue({
      data: {
        id: "0190aaaa-bbbb-7ccc-8ddd-0e1f2a3b4c5d",
        email: "a@b.com",
        roles: ["ADMIN"],
        permissions: ["bank.read", "users.read", "auditTrail.read", "bankAccount.changeStatus"],
      },
    });

    const identity = await new ApiIdentityRepository(httpClientGetting(get)).me();

    expect(identity?.permissions).toEqual(["users.read"]);
  });

  // A wildcard is a client-side convenience the server never emits: `*` is not a permission it can express.
  it("does not invent a wildcard from a full permission set", async () => {
    const get = vi.fn().mockResolvedValue({
      data: {
        id: "0190aaaa-bbbb-7ccc-8ddd-0e1f2a3b4c5d",
        email: "a@b.com",
        roles: ["ADMIN"],
        permissions: [
          "users.read",
          "users.invite",
          "users.changeStatus",
          "users.changeRoles",
          "users.erase",
        ],
      },
    });

    const identity = await new ApiIdentityRepository(httpClientGetting(get)).me();

    expect(identity?.permissions).not.toContain("*");
  });

  it("rejects a body whose permissions are not all strings", async () => {
    const body = {
      data: {
        id: "0190aaaa-bbbb-7ccc-8ddd-0e1f2a3b4c5d",
        email: "a@b.com",
        roles: ["ADMIN"],
        permissions: ["users.read", 42],
      },
    };

    await expect(
      new ApiIdentityRepository(httpClientGetting(guardingGet(body))).me(),
    ).rejects.toThrow(HttpError);
  });

  it("rejects a body with no permissions field", async () => {
    const body = {
      data: {
        id: "0190aaaa-bbbb-7ccc-8ddd-0e1f2a3b4c5d",
        email: "a@b.com",
        roles: ["ADMIN"],
      },
    };

    await expect(
      new ApiIdentityRepository(httpClientGetting(guardingGet(body))).me(),
    ).rejects.toThrow(HttpError);
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

  it("passes a well-formed { data } envelope through the guard and maps it", async () => {
    const get = guardingGet({
      data: {
        id: "0190aaaa-bbbb-7ccc-8ddd-0e1f2a3b4c5d",
        email: "a@b.com",
        roles: ["ADMIN"],
        permissions: ["users.read"],
      },
    });

    const identity = await new ApiIdentityRepository(httpClientGetting(get)).me();

    expect(identity).toMatchObject({ email: "a@b.com", roles: ["ADMIN"] });
  });

  it("rejects a flat body with no { data } envelope as malformed", async () => {
    // /me answers with the { data } envelope; a flat object is not a valid identity response.
    const get = guardingGet({
      id: "0190aaaa-bbbb-7ccc-8ddd-0e1f2a3b4c5d",
      email: "a@b.com",
      roles: ["ADMIN"],
      permissions: ["users.read"],
    });

    await expect(new ApiIdentityRepository(httpClientGetting(get)).me()).rejects.toMatchObject({
      problem: { type: MALFORMED_RESPONSE_ENVELOPE },
    });
  });

  it("rejects an envelope whose data is null", async () => {
    const get = guardingGet({ data: null });

    await expect(new ApiIdentityRepository(httpClientGetting(get)).me()).rejects.toMatchObject({
      problem: { type: MALFORMED_RESPONSE_ENVELOPE },
    });
  });

  it("rejects an envelope whose roles holds a non-string", async () => {
    const get = guardingGet({
      data: {
        id: "0190aaaa-bbbb-7ccc-8ddd-0e1f2a3b4c5d",
        email: "a@b.com",
        roles: ["ADMIN", 7],
        permissions: ["users.read"],
      },
    });

    await expect(new ApiIdentityRepository(httpClientGetting(get)).me()).rejects.toMatchObject({
      problem: { type: MALFORMED_RESPONSE_ENVELOPE },
    });
  });
});
