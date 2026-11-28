import { describe, expect, it } from "vitest";
import {
  evidenceAttachProblemMessage,
  liveDocumentsOf,
} from "@/context/backoffice/bankaccount/domain/evidenceProblemMessage";
import type { ProblemDetails } from "@/context/shared/error/domain/ProblemDetails";

function problem(
  type: string,
  status: number,
  extra: Record<string, unknown> = {},
): ProblemDetails {
  return { type, title: "server title", status, instance: "i", "correlation-id": "c", ...extra };
}

describe("evidenceAttachProblemMessage", () => {
  it("maps the attach refusals on `type`", () => {
    expect(
      evidenceAttachProblemMessage(
        problem("bank-account-evidence-retention-mismatch", 422),
        "attach",
      ),
    ).toMatch(/different retention terms/);
    expect(
      evidenceAttachProblemMessage(
        problem("bank-account-evidence-already-attached", 409),
        "attach",
      ),
    ).toBe("This document is already attached to the account.");
    expect(
      evidenceAttachProblemMessage(problem("bank-account-evidence-unavailable", 422), "attach"),
    ).toMatch(/cannot be attached/);
  });

  it("reads `gone` on the attach as frozen documents and on the upload as an expired upload", () => {
    expect(evidenceAttachProblemMessage(problem("gone", 410), "attach")).toMatch(/read-only/);
    expect(evidenceAttachProblemMessage(problem("gone", 410), "upload")).toMatch(/expired/);
  });

  it("falls back per stage", () => {
    expect(evidenceAttachProblemMessage(problem("about:blank", 500), "upload")).toBe(
      "The file could not be uploaded. Try again.",
    );
    expect(evidenceAttachProblemMessage(problem("about:blank", 500), "attach")).toMatch(/was kept/);
  });
});

describe("liveDocumentsOf", () => {
  it("reads a non-negative integer and nothing else", () => {
    expect(liveDocumentsOf(problem("x", 409, { liveDocuments: 2 }))).toBe(2);
    expect(liveDocumentsOf(problem("x", 409, { liveDocuments: "2" }))).toBeNull();
    expect(liveDocumentsOf(problem("x", 409))).toBeNull();
  });
});
