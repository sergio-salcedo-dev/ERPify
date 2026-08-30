import { describe, expect, it, vi } from "vitest";
import { ApiRedeemRecoverySecretRepository } from "@/context/backoffice/user/infrastructure/ApiRedeemRecoverySecretRepository";
import {
  RedeemRecoverySecretOutcomeKind,
  RedeemRecoverySecretProblemType,
} from "@/context/backoffice/user/domain/RedeemRecoverySecretOutcome";
import { HttpError } from "@/context/shared/http-client/domain/HttpError";
import { HttpStatus } from "@/context/shared/http-client/domain/HttpStatus";
import { API_ENDPOINTS } from "@/context/shared/http-client/infrastructure/ApiEndpoints";
import type { HttpClient } from "@/context/shared/http-client/domain/HttpClient";
import type { ProblemDetails } from "@/context/shared/error/domain/ProblemDetails";

const SECRET = "0190b1c2-d3e4-7f5a-8b6c-1d2e3f4a5b60.super-secret-verifier";

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

function redeemWith(post: HttpClient["post"]) {
  return new ApiRedeemRecoverySecretRepository(httpClientPosting(post)).redeem(SECRET);
}

describe("ApiRedeemRecoverySecretRepository.redeem", () => {
  it("maps a 204 to redeemed and posts the secret in the body", async () => {
    const post = vi.fn().mockResolvedValue(undefined);

    const outcome = await redeemWith(post);

    expect(outcome.kind).toBe(RedeemRecoverySecretOutcomeKind.REDEEMED);
    // The credential is a body member and nothing else: a path segment or a query parameter
    // would put it in the address bar, the history entry and every access log in front of the
    // application — for a credential that stays valid for ten years.
    expect(post).toHaveBeenCalledWith(
      API_ENDPOINTS.BACKOFFICE.RECOVERY_REDEEM,
      { secret: SECRET },
      undefined,
      { headers: expect.objectContaining({ "X-CSRF-Token": expect.any(String) }) },
    );
  });

  it("asks for no response guard, so an empty 204 is not a malformed envelope", async () => {
    const post = vi.fn().mockResolvedValue(undefined);

    await redeemWith(post);

    expect(post.mock.calls[0][2]).toBeUndefined();
  });

  it("sends the CSRF nonce as a header, never as a body member", async () => {
    const post = vi.fn().mockResolvedValue(undefined);

    await redeemWith(post);

    // The endpoint rejects any body member its payload does not declare, and a custom header
    // cannot be forged by a cross-origin form post without clearing a preflight.
    expect(post.mock.calls[0][1]).toEqual({ secret: SECRET });
    const { headers } = post.mock.calls[0][3] as { headers: Record<string, string> };
    // A v7 UUID is 36 characters, comfortably over the backend's length floor.
    expect(headers["X-CSRF-Token"].length).toBeGreaterThanOrEqual(24);
  });

  it("mints a fresh nonce per attempt, so a retry never replays the last one", async () => {
    const post = vi.fn().mockResolvedValue(undefined);
    const repository = new ApiRedeemRecoverySecretRepository(httpClientPosting(post));

    await repository.redeem(SECRET);
    await repository.redeem(SECRET);

    const nonceOf = (call: number) =>
      (post.mock.calls[call][3] as { headers: Record<string, string> }).headers["X-CSRF-Token"];
    expect(nonceOf(0)).not.toBe(nonceOf(1));
  });

  /**
   * Malformed, unknown, expired, already spent and budget-exhausted all answer the one type,
   * by design: telling them apart tells whoever is guessing which half of a presented secret
   * was right. So the mapping is on the type alone and the status is not consulted — a 400 and
   * a 429 wearing `invalid-token` are the same outcome here.
   */
  it.each([
    ["a 400", HttpStatus.BAD_REQUEST],
    ["a 429 with the budget spent", HttpStatus.TOO_MANY_REQUESTS],
  ])(
    "maps %s invalid-token to the single opaque invalid-secret outcome",
    async (_label, status) => {
      const post = vi
        .fn()
        .mockRejectedValue(
          new HttpError(problem(status, RedeemRecoverySecretProblemType.INVALID_TOKEN)),
        );

      const outcome = await redeemWith(post);

      expect(outcome.kind).toBe(RedeemRecoverySecretOutcomeKind.INVALID_SECRET);
    },
  );

  it.each([
    [RedeemRecoverySecretProblemType.ACCOUNT_SUSPENDED, RedeemRecoverySecretOutcomeKind.SUSPENDED],
    [
      RedeemRecoverySecretProblemType.ACCOUNT_DEACTIVATED,
      RedeemRecoverySecretOutcomeKind.DEACTIVATED,
    ],
  ])("maps a 403 %s to the matching terminal wall", async (type, kind) => {
    const post = vi.fn().mockRejectedValue(new HttpError(problem(HttpStatus.FORBIDDEN, type)));

    expect((await redeemWith(post)).kind).toBe(kind);
  });

  it("re-throws a generic 403 — an origin or CSRF rejection is not an account state", async () => {
    // Reading the status alone would render the terminal "account is not active" wall over a
    // request that was merely refused at the door, and that wall invites no retry.
    const error = new HttpError(problem(HttpStatus.FORBIDDEN, "forbidden"));
    const post = vi.fn().mockRejectedValue(error);

    await expect(redeemWith(post)).rejects.toBe(error);
  });

  it.each([
    ["an account state carried on the wrong status", HttpStatus.BAD_REQUEST, "account-suspended"],
    ["a 401", HttpStatus.UNAUTHORIZED, "unauthenticated"],
    ["a 503 from an unavailable session store", HttpStatus.SERVICE_UNAVAILABLE, "server-error"],
  ])("re-throws %s — it is not a redeem outcome", async (_label, status, type) => {
    const error = new HttpError(problem(status, type));
    const post = vi.fn().mockRejectedValue(error);

    await expect(redeemWith(post)).rejects.toBe(error);
  });

  it("re-throws a non-HTTP transport failure", async () => {
    const boom = new Error("network down");
    const post = vi.fn().mockRejectedValue(boom);

    await expect(redeemWith(post)).rejects.toBe(boom);
  });
});
