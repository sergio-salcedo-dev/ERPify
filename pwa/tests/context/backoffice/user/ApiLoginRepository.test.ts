import { describe, expect, it, vi } from "vitest";
import { ApiLoginRepository } from "@/context/backoffice/user/infrastructure/ApiLoginRepository";
import { LoginOutcomeKind } from "@/context/backoffice/user/domain/LoginOutcome";
import { HttpError } from "@/context/shared/http-client/domain/HttpError";
import { HttpStatus } from "@/context/shared/http-client/domain/HttpStatus";
import { API_ENDPOINTS } from "@/context/shared/http-client/infrastructure/ApiEndpoints";
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

function httpClientPosting(post: HttpClient["post"]): HttpClient {
  return {
    get: vi.fn(),
    post,
    put: vi.fn(),
    patch: vi.fn(),
    delete: vi.fn(),
  };
}

describe("ApiLoginRepository.login", () => {
  it("maps a 204 to authenticated and posts the credentials to the login endpoint", async () => {
    const post = vi.fn().mockResolvedValue(undefined);
    const credentials = { email: "a@b.com", password: "secret123" };

    const outcome = await new ApiLoginRepository(httpClientPosting(post)).login(credentials);

    expect(outcome.kind).toBe(LoginOutcomeKind.AUTHENTICATED);
    expect(post).toHaveBeenCalledWith(API_ENDPOINTS.BACKOFFICE.LOGIN, credentials);
  });

  it("maps 403 account-suspended to suspended", async () => {
    const post = vi
      .fn()
      .mockRejectedValue(new HttpError(problem(HttpStatus.FORBIDDEN, "account-suspended")));

    const outcome = await new ApiLoginRepository(httpClientPosting(post)).login({
      email: "a@b.com",
      password: "x",
    });

    expect(outcome.kind).toBe(LoginOutcomeKind.SUSPENDED);
  });

  it("maps 403 forbidden to deactivated", async () => {
    const post = vi
      .fn()
      .mockRejectedValue(new HttpError(problem(HttpStatus.FORBIDDEN, "forbidden")));

    const outcome = await new ApiLoginRepository(httpClientPosting(post)).login({
      email: "a@b.com",
      password: "x",
    });

    expect(outcome.kind).toBe(LoginOutcomeKind.DEACTIVATED);
  });

  it.each([
    ["401 unauthenticated", HttpStatus.UNAUTHORIZED, "unauthenticated"],
    ["a 403 with an unrecognised type", HttpStatus.FORBIDDEN, "some-other-type"],
    ["a 500", HttpStatus.INTERNAL_SERVER_ERROR, "server-error"],
  ])("collapses %s to invalid-credentials (neutrality)", async (_label, status, type) => {
    const post = vi.fn().mockRejectedValue(new HttpError(problem(status, type)));

    const outcome = await new ApiLoginRepository(httpClientPosting(post)).login({
      email: "a@b.com",
      password: "x",
    });

    expect(outcome.kind).toBe(LoginOutcomeKind.INVALID_CREDENTIALS);
  });

  it("re-throws a non-HTTP transport failure — it is not a login outcome", async () => {
    const boom = new Error("network down");
    const post = vi.fn().mockRejectedValue(boom);

    await expect(
      new ApiLoginRepository(httpClientPosting(post)).login({ email: "a@b.com", password: "x" }),
    ).rejects.toBe(boom);
  });
});
