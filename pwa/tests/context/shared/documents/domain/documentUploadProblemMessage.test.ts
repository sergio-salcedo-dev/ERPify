import { describe, expect, it } from "vitest";
import { documentUploadProblemMessage } from "@/context/shared/documents/domain/documentUploadProblemMessage";
import type { ProblemDetails } from "@/context/shared/error/domain/ProblemDetails";

function problem(
  type: string,
  status: number,
  extra: Record<string, unknown> = {},
): ProblemDetails {
  return { type, title: "server title", status, instance: "i", "correlation-id": "c", ...extra };
}

describe("documentUploadProblemMessage", () => {
  it("routes the ingest types on `type`", () => {
    expect(documentUploadProblemMessage(problem("invalid-filename", 422))).toMatch(
      /Rename the file/,
    );
    expect(documentUploadProblemMessage(problem("aborted-upload", 422))).toMatch(/interrupted/);
    expect(documentUploadProblemMessage(problem("gone", 410))).toMatch(/expired/);
  });

  it("reads the throttle's wait when it is given", () => {
    expect(
      documentUploadProblemMessage(problem("rate-limited", 429, { retryAfterSeconds: 5 })),
    ).toBe("Too many uploads in a short time. Wait 5 seconds and try again.");
  });

  it("answers null for a problem these routes do not produce", () => {
    expect(documentUploadProblemMessage(problem("bank-account-not-found", 404))).toBeNull();
  });
});
