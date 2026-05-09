import { afterEach, beforeEach, describe, expect, it, vi, type MockInstance } from "vitest";
import { toProblemDetails } from "@/context/shared/infrastructure/HttpClient/legacyEnvelope";
import { isProblemDetails, type ProblemDetails } from "@/context/shared/domain/ProblemDetails";

const FALLBACK = { type: "about:blank", title: "HTTP 500" } as const;
const STUB_UUID = "00000000-0000-4000-8000-000000000000";

describe("toProblemDetails", () => {
  let randomUUIDSpy: MockInstance<() => `${string}-${string}-${string}-${string}-${string}`>;

  beforeEach(() => {
    randomUUIDSpy = vi.spyOn(crypto, "randomUUID").mockReturnValue(STUB_UUID);
  });

  afterEach(() => {
    randomUUIDSpy.mockRestore();
  });

  it("returns the same object when the body is already ProblemDetails", () => {
    const problem: ProblemDetails = {
      type: "validation-failed",
      title: "Validation failed",
      status: 422,
      instance: "01H-instance",
      "correlation-id": "01H-correlation",
      violations: [{ field: "name", message: "required" }],
    };

    const result = toProblemDetails(problem, 422, FALLBACK);

    expect(result).toBe(problem);
    expect(isProblemDetails(result)).toBe(true);
  });

  it("translates a legacy single-error 422", () => {
    const body = {
      errors: [{ source: { parameter: "name" }, title: "required" }],
      meta: { requestId: "01H-correlation" },
    };

    const result = toProblemDetails(body, 422, FALLBACK);

    expect(result).toEqual({
      type: "about:blank",
      title: "required",
      status: 422,
      instance: STUB_UUID,
      "correlation-id": "01H-correlation",
      violations: [{ field: "name", message: "required" }],
    });
    expect(isProblemDetails(result)).toBe(true);
  });

  it("translates a legacy multi-error 422 preserving order", () => {
    const body = {
      errors: [
        { source: { parameter: "name" }, title: "name required" },
        { source: { parameter: "shortName" }, title: "shortName required" },
      ],
      meta: { requestId: "01H-correlation" },
    };

    const result = toProblemDetails(body, 422, FALLBACK);

    expect(result.title).toBe("name required");
    expect(result.violations).toEqual([
      { field: "name", message: "name required" },
      { field: "shortName", message: "shortName required" },
    ]);
  });

  it("translates a legacy 404 with one error", () => {
    const body = {
      errors: [{ source: { parameter: "uuid" }, title: "Bank not found" }],
      meta: { requestId: "01H-correlation" },
    };

    const result = toProblemDetails(body, 404, FALLBACK);

    expect(result.status).toBe(404);
    expect(result.title).toBe("Bank not found");
    expect(result.violations).toEqual([{ field: "uuid", message: "Bank not found" }]);
  });

  it("mints a UUID for correlation-id when meta.requestId is missing", () => {
    const body = {
      errors: [{ source: { parameter: "name" }, title: "required" }],
    };

    const result = toProblemDetails(body, 422, FALLBACK);

    expect(result["correlation-id"]).toBe(STUB_UUID);
  });

  it("falls back to an empty field when source.parameter is missing", () => {
    const body = {
      errors: [{ title: "Boom" }],
      meta: { requestId: "01H-correlation" },
    };

    const result = toProblemDetails(body, 500, FALLBACK);

    expect(result.violations).toEqual([{ field: "", message: "Boom" }]);
  });

  it("uses the fallback title and omits violations when errors[] is empty", () => {
    const body = {
      errors: [],
      meta: { requestId: "01H-correlation" },
    };

    const result = toProblemDetails(body, 500, FALLBACK);

    expect(result.title).toBe(FALLBACK.title);
    expect(result.violations).toBeUndefined();
    expect(result["correlation-id"]).toBe("01H-correlation");
  });

  it("synthesizes a ProblemDetails when the body is null", () => {
    const result = toProblemDetails(null, 500, FALLBACK);

    expect(result).toEqual({
      type: FALLBACK.type,
      title: FALLBACK.title,
      status: 500,
      instance: STUB_UUID,
      "correlation-id": STUB_UUID,
    });
    expect(isProblemDetails(result)).toBe(true);
  });

  it("synthesizes a ProblemDetails when the body is a non-object", () => {
    const result = toProblemDetails("not json", 500, FALLBACK);

    expect(isProblemDetails(result)).toBe(true);
    expect(result.status).toBe(500);
  });

  it("synthesizes a ProblemDetails when the body has no errors[] field", () => {
    const result = toProblemDetails({ message: "stray" }, 500, FALLBACK);

    expect(isProblemDetails(result)).toBe(true);
    expect(result.title).toBe(FALLBACK.title);
  });

  it("survives a null entry inside errors[]", () => {
    const body = {
      errors: [null, { source: { parameter: "name" }, title: "required" }],
      meta: { requestId: "01H-correlation" },
    };

    const result = toProblemDetails(body, 422, FALLBACK);

    expect(result.violations).toEqual([
      { field: "", message: "" },
      { field: "name", message: "required" },
    ]);
  });
});
