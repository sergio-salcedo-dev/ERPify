import { describe, expect, it, vi } from "vitest";
import { ApiResetPasswordRepository } from "@/context/backoffice/user/infrastructure/ApiResetPasswordRepository";
import { ResetPasswordOutcomeKind } from "@/context/backoffice/user/domain/ResetPasswordOutcome";
import { HttpError } from "@/context/shared/http-client/domain/HttpError";
import { HttpStatus } from "@/context/shared/http-client/domain/HttpStatus";
import { API_ENDPOINTS } from "@/context/shared/http-client/infrastructure/ApiEndpoints";
import type { HttpClient } from "@/context/shared/http-client/domain/HttpClient";
import type {
  ProblemDetails,
  ProblemViolation,
} from "@/context/shared/error/domain/ProblemDetails";

const COMMAND = { token: "opaque-reset-token", password: "a-strong-password" };

function problem(status: number, type: string, violations?: ProblemViolation[]): ProblemDetails {
  return {
    type,
    title: type,
    status,
    instance: "0190ffff-aaaa-7bbb-8ccc-0d1e2f3a4b5c",
    "correlation-id": "0190ffff-aaaa-7bbb-8ccc-0d1e2f3a4b5d",
    ...(violations ? { violations } : {}),
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

describe("ApiResetPasswordRepository.reset", () => {
  it("maps a 204 to reset and posts {token, password} to the reset endpoint", async () => {
    const post = vi.fn().mockResolvedValue(undefined);

    const outcome = await new ApiResetPasswordRepository(httpClientPosting(post)).reset(COMMAND);

    expect(outcome.kind).toBe(ResetPasswordOutcomeKind.RESET);
    expect(post).toHaveBeenCalledWith(
      API_ENDPOINTS.BACKOFFICE.RESET_PASSWORD,
      { token: COMMAND.token, password: COMMAND.password },
      undefined,
      expect.objectContaining({ "X-CSRF-Token": expect.any(String) }),
    );
  });

  it("sends the CSRF nonce as a header, never as a body member", async () => {
    const post = vi.fn().mockResolvedValue(undefined);

    await new ApiResetPasswordRepository(httpClientPosting(post)).reset(COMMAND);

    // The body is the application contract: the endpoint rejects any member it does
    // not declare, so the nonce must not appear there.
    expect(post.mock.calls[0][1]).not.toHaveProperty("_token");
    // The nonce satisfies the backend's >= 24 char contract (a v7 UUID is 36 chars).
    const headers = post.mock.calls[0][3] as Record<string, string>;
    expect(headers["X-CSRF-Token"].length).toBeGreaterThanOrEqual(24);
  });

  it("maps a dead token (400 invalid-token) to invalid-link", async () => {
    const post = vi
      .fn()
      .mockRejectedValue(new HttpError(problem(HttpStatus.BAD_REQUEST, "invalid-token")));

    const outcome = await new ApiResetPasswordRepository(httpClientPosting(post)).reset(COMMAND);

    expect(outcome.kind).toBe(ResetPasswordOutcomeKind.INVALID_LINK);
  });

  it("maps a 403 account-suspended to suspended", async () => {
    const post = vi
      .fn()
      .mockRejectedValue(new HttpError(problem(HttpStatus.FORBIDDEN, "account-suspended")));

    const outcome = await new ApiResetPasswordRepository(httpClientPosting(post)).reset(COMMAND);

    expect(outcome.kind).toBe(ResetPasswordOutcomeKind.SUSPENDED);
  });

  it("maps a 403 account-deactivated to deactivated", async () => {
    const post = vi
      .fn()
      .mockRejectedValue(new HttpError(problem(HttpStatus.FORBIDDEN, "account-deactivated")));

    const outcome = await new ApiResetPasswordRepository(httpClientPosting(post)).reset(COMMAND);

    expect(outcome.kind).toBe(ResetPasswordOutcomeKind.DEACTIVATED);
  });

  it("maps a 422 validation-failed to validation-error carrying the violations", async () => {
    const violations: ProblemViolation[] = [
      { field: "password", message: "La contraseña es demasiado débil.", code: "weak" },
    ];
    const post = vi
      .fn()
      .mockRejectedValue(
        new HttpError(problem(HttpStatus.UNPROCESSABLE_ENTITY, "validation-failed", violations)),
      );

    const outcome = await new ApiResetPasswordRepository(httpClientPosting(post)).reset(COMMAND);

    expect(outcome.kind).toBe(ResetPasswordOutcomeKind.VALIDATION_ERROR);
    if (outcome.kind === ResetPasswordOutcomeKind.VALIDATION_ERROR) {
      expect(outcome.violations).toEqual(violations);
    }
  });

  it.each([
    ["a generic 403 forbidden (origin/CSRF rejection)", HttpStatus.FORBIDDEN, "forbidden"],
    ["a 403 that is neither suspended nor deactivated", HttpStatus.FORBIDDEN, "account-locked"],
    ["a 401", HttpStatus.UNAUTHORIZED, "unauthenticated"],
    ["a 500", HttpStatus.INTERNAL_SERVER_ERROR, "server-error"],
  ])("re-throws %s — it is not a reset outcome", async (_label, status, type) => {
    const error = new HttpError(problem(status, type));
    const post = vi.fn().mockRejectedValue(error);

    await expect(
      new ApiResetPasswordRepository(httpClientPosting(post)).reset(COMMAND),
    ).rejects.toBe(error);
  });

  it("re-throws a non-HTTP transport failure", async () => {
    const boom = new Error("network down");
    const post = vi.fn().mockRejectedValue(boom);

    await expect(
      new ApiResetPasswordRepository(httpClientPosting(post)).reset(COMMAND),
    ).rejects.toBe(boom);
  });
});
