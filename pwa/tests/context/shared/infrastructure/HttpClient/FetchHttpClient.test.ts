import { afterEach, beforeEach, describe, expect, it, vi, type MockInstance } from "vitest";
import { FetchHttpClient } from "@/context/shared/infrastructure/HttpClient/HttpClient";
import { HttpError } from "@/context/shared/infrastructure/HttpClient/HttpError";
import { isProblemDetails } from "@/context/shared/domain/ProblemDetails";

const STUB_UUID = "00000000-0000-4000-8000-000000000000";

function makeResponse(
  status: number,
  body: unknown,
  init: { contentType?: string; headers?: Record<string, string> } = {},
): Response {
  const headers = new Headers({
    "Content-Type": init.contentType ?? "application/json",
    ...(init.headers ?? {}),
  });
  return new Response(body === undefined ? null : JSON.stringify(body), {
    status,
    headers,
  });
}

describe("FetchHttpClient", () => {
  let fetchSpy: MockInstance;
  let randomUUIDSpy: MockInstance<() => `${string}-${string}-${string}-${string}-${string}`>;

  beforeEach(() => {
    fetchSpy = vi.spyOn(globalThis, "fetch");
    randomUUIDSpy = vi.spyOn(crypto, "randomUUID").mockReturnValue(STUB_UUID);
  });

  afterEach(() => {
    fetchSpy.mockRestore();
    randomUUIDSpy.mockRestore();
  });

  it("passes through an RFC 9457 problem body verbatim on 4xx", async () => {
    const wireProblem = {
      type: "bank-not-found",
      title: "Bank not found.",
      status: 404,
      instance: "01H-instance",
      "correlation-id": "01H-correlation",
    };
    fetchSpy.mockResolvedValueOnce(
      makeResponse(404, wireProblem, { contentType: "application/problem+json" }),
    );

    const client = new FetchHttpClient();

    await expect(client.get("/api/v1/backoffice/banks/missing")).rejects.toMatchObject({
      problem: wireProblem,
    });
  });

  it("forwards violations[] from a validation-failed 400", async () => {
    const wireProblem = {
      type: "validation-failed",
      title: "Validation failed.",
      status: 400,
      instance: "01H-instance",
      "correlation-id": "01H-correlation",
      violations: [{ field: "name", message: "The name field is required." }],
    };
    fetchSpy.mockResolvedValueOnce(
      makeResponse(400, wireProblem, { contentType: "application/problem+json" }),
    );

    const client = new FetchHttpClient();

    try {
      await client.post("/api/v1/backoffice/banks", { name: "" });
      throw new Error("expected HttpError");
    } catch (err) {
      expect(err).toBeInstanceOf(HttpError);
      const httpError = err as HttpError;
      expect(httpError.problem.type).toBe("validation-failed");
      expect(httpError.problem.violations).toEqual([
        { field: "name", message: "The name field is required." },
      ]);
      expect(isProblemDetails(httpError.problem)).toBe(true);
    }
  });

  it("synthesizes a generic ProblemDetails when the body is not RFC 9457", async () => {
    fetchSpy.mockResolvedValueOnce(
      makeResponse(
        500,
        { message: "boom" },
        {
          headers: { "X-Correlation-Id": "01H-correlation-from-header" },
        },
      ),
    );

    const client = new FetchHttpClient();

    try {
      await client.get("/api/v1/backoffice/banks");
      throw new Error("expected HttpError");
    } catch (err) {
      const httpError = err as HttpError;
      expect(httpError.problem).toEqual({
        type: "about:blank",
        title: "HTTP 500",
        status: 500,
        instance: STUB_UUID,
        "correlation-id": "01H-correlation-from-header",
      });
      expect(isProblemDetails(httpError.problem)).toBe(true);
    }
  });

  it("falls back to a synthetic correlation-id when the header is absent", async () => {
    fetchSpy.mockResolvedValueOnce(makeResponse(502, null));

    const client = new FetchHttpClient();

    try {
      await client.delete("/api/v1/backoffice/banks/anything");
      throw new Error("expected HttpError");
    } catch (err) {
      const httpError = err as HttpError;
      expect(httpError.problem["correlation-id"]).toBe(STUB_UUID);
      expect(httpError.problem.status).toBe(502);
    }
  });
});
