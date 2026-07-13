import { describe, expect, it, vi } from "vitest";
import { ApiForgotPasswordRepository } from "@/context/backoffice/user/infrastructure/ApiForgotPasswordRepository";
import { HttpError } from "@/context/shared/http-client/domain/HttpError";
import { HttpStatus } from "@/context/shared/http-client/domain/HttpStatus";
import { API_ENDPOINTS } from "@/context/shared/http-client/infrastructure/ApiEndpoints";
import type { HttpClient } from "@/context/shared/http-client/domain/HttpClient";
import type { ProblemDetails } from "@/context/shared/error/domain/ProblemDetails";

const COMMAND = { email: "user@example.com" };

function httpClientPosting(post: HttpClient["post"]): HttpClient {
  return {
    get: vi.fn(),
    post,
    put: vi.fn(),
    patch: vi.fn(),
    delete: vi.fn(),
  };
}

describe("ApiForgotPasswordRepository.request", () => {
  it("posts {email} to the forgot-password endpoint and resolves on the uniform 202", async () => {
    const post = vi.fn().mockResolvedValue(undefined);

    await expect(
      new ApiForgotPasswordRepository(httpClientPosting(post)).request(COMMAND),
    ).resolves.toBeUndefined();

    expect(post).toHaveBeenCalledWith(API_ENDPOINTS.BACKOFFICE.FORGOT_PASSWORD, {
      email: COMMAND.email,
    });
    // No CSRF nonce: the route is same-origin only. The body carries exactly {email}.
    expect(post.mock.calls[0][1]).not.toHaveProperty("_token");
  });

  it("propagates a transport fault so the caller can surface a neutral error", async () => {
    const boom = new Error("network down");
    const post = vi.fn().mockRejectedValue(boom);

    await expect(
      new ApiForgotPasswordRepository(httpClientPosting(post)).request(COMMAND),
    ).rejects.toBe(boom);
  });

  it("propagates an HTTP error (it never routes on problem type)", async () => {
    const problem: ProblemDetails = {
      type: "validation-failed",
      title: "validation-failed",
      status: HttpStatus.UNPROCESSABLE_ENTITY,
      instance: "0190ffff-aaaa-7bbb-8ccc-0d1e2f3a4b5c",
      "correlation-id": "0190ffff-aaaa-7bbb-8ccc-0d1e2f3a4b5d",
    };
    const error = new HttpError(problem);
    const post = vi.fn().mockRejectedValue(error);

    await expect(
      new ApiForgotPasswordRepository(httpClientPosting(post)).request(COMMAND),
    ).rejects.toBe(error);
  });
});
