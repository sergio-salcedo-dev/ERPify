import { describe, expect, it } from "vitest";
import {
  evidenceAttachProblemMessage,
  isEvidenceOutcomeUnknown,
  isEvidenceRetryable,
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

const UPLOAD_SENT = { stage: "upload", contentSent: true } as const;
const UPLOAD_DECLARE = { stage: "upload", contentSent: false } as const;
const ATTACH = { stage: "attach", contentSent: true } as const;

describe("isEvidenceOutcomeUnknown", () => {
  it("is unknown during the body on a lost connection, a timeout or a gateway answer", () => {
    for (const p of [
      problem("network-error", 0),
      problem("request-timeout", 0),
      problem("about:blank", 502),
      problem("about:blank", 503),
      problem("about:blank", 504),
    ]) {
      expect(isEvidenceOutcomeUnknown(p, UPLOAD_SENT)).toBe(true);
    }
  });

  it("is known when the declare never answered, or the application itself answered 500", () => {
    expect(isEvidenceOutcomeUnknown(problem("network-error", 0), UPLOAD_DECLARE)).toBe(false);
    expect(isEvidenceOutcomeUnknown(problem("about:blank", 500), UPLOAD_SENT)).toBe(false);
  });

  it("is unknown at the attach on any 5xx", () => {
    expect(isEvidenceOutcomeUnknown(problem("about:blank", 500), ATTACH)).toBe(true);
    expect(
      isEvidenceOutcomeUnknown(problem("bank-account-evidence-unavailable", 422), ATTACH),
    ).toBe(false);
  });
});

describe("isEvidenceRetryable", () => {
  it("never offers a blind re-upload when the upload's outcome is unknown", () => {
    expect(isEvidenceRetryable(problem("request-timeout", 0), UPLOAD_SENT)).toBe(false);
    expect(isEvidenceRetryable(problem("about:blank", 503), UPLOAD_SENT)).toBe(false);
  });

  it.each([
    ["network-error before the body", "network-error", 0, UPLOAD_DECLARE],
    ["a 500", "about:blank", 500, UPLOAD_SENT],
    ["throttled", "rate-limited", 429, UPLOAD_DECLARE],
    ["an expired intent", "gone", 410, UPLOAD_SENT],
    ["an aborted upload", "aborted-upload", 422, UPLOAD_SENT],
    ["no length", "length-required", 411, UPLOAD_SENT],
  ] as const)("re-uploads after %s", (_l, type, status, context) => {
    expect(isEvidenceRetryable(problem(type, status), context)).toBe(true);
  });

  it.each([
    ["forbidden", "forbidden", 403],
    ["validation", "validation-failed", 422],
    ["a refused filename", "invalid-filename", 422],
    ["an unknown 4xx", "about:blank", 400],
  ] as const)("does not re-upload after %s", (_l, type, status) => {
    expect(isEvidenceRetryable(problem(type, status), UPLOAD_SENT)).toBe(false);
  });

  it("retries an attach that may have failed transiently, never one the server refused for good", () => {
    expect(isEvidenceRetryable(problem("network-error", 0), ATTACH)).toBe(true);
    expect(isEvidenceRetryable(problem("about:blank", 503), ATTACH)).toBe(true);
    expect(isEvidenceRetryable(problem("rate-limited", 429), ATTACH)).toBe(true);
    expect(isEvidenceRetryable(problem("gone", 410), ATTACH)).toBe(true);
    for (const p of [
      problem("bank-account-evidence-unavailable", 422),
      problem("bank-account-evidence-retention-mismatch", 422),
      problem("forbidden", 403),
      problem("bank-account-not-found", 404),
    ]) {
      expect(isEvidenceRetryable(p, ATTACH)).toBe(false);
    }
  });
});

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
  });

  it("reads `gone` on the attach as frozen documents and on the upload as an expired upload", () => {
    expect(evidenceAttachProblemMessage(problem("gone", 410), "attach")).toMatch(/read-only/);
    expect(evidenceAttachProblemMessage(problem("gone", 410), "upload")).toMatch(/expired/);
  });

  it("never advises checking the list, which shows attached evidence only", () => {
    expect(evidenceAttachProblemMessage(problem("request-timeout", 0), "upload")).not.toMatch(
      /list/i,
    );
  });

  it("falls back per stage", () => {
    expect(evidenceAttachProblemMessage(problem("about:blank", 500), "upload")).toBe(
      "The file could not be uploaded.",
    );
    expect(evidenceAttachProblemMessage(problem("about:blank", 500), "attach")).toBe(
      "The document could not be attached.",
    );
  });
});

describe("liveDocumentsOf", () => {
  it("reads a non-negative integer and nothing else", () => {
    expect(liveDocumentsOf(problem("x", 409, { liveDocuments: 2 }))).toBe(2);
    expect(liveDocumentsOf(problem("x", 409, { liveDocuments: "2" }))).toBeNull();
    expect(liveDocumentsOf(problem("x", 409))).toBeNull();
  });
});
