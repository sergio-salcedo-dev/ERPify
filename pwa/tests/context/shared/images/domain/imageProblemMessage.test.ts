import { describe, expect, it } from "vitest";
import type { ProblemDetails } from "@/context/shared/error/domain/ProblemDetails";
import { NETWORK_ERROR, REQUEST_TIMEOUT } from "@/context/shared/http-client/domain/HttpClient";
import {
  ImageAction,
  imageProblemMessage,
} from "@/context/shared/images/domain/imageProblemMessage";
import { ImageProblemType } from "@/context/shared/images/domain/ImageProblemType";

function problem(
  type: string,
  status: number,
  extra: Record<string, unknown> = {},
): ProblemDetails {
  return {
    type,
    title: "Server title that must not be shown",
    status,
    instance: "i",
    "correlation-id": "c",
    ...extra,
  };
}

describe("imageProblemMessage", () => {
  const cases: Array<[string, ProblemDetails]> = [
    ["image-length-required", problem(ImageProblemType.LENGTH_REQUIRED, 411)],
    ["image-too-large", problem(ImageProblemType.TOO_LARGE, 413)],
    ["image-unsupported-type", problem(ImageProblemType.UNSUPPORTED_TYPE, 415)],
    ["image-invalid", problem(ImageProblemType.INVALID, 422)],
    ["invalid-uuid", problem("invalid-uuid", 400)],
    ["401", problem("unauthenticated", 401)],
    ["403", problem("forbidden", 403)],
    ["404", problem("bank-not-found", 404)],
    ["429", problem("rate-limited", 429)],
    [NETWORK_ERROR, problem(NETWORK_ERROR, 0)],
    [REQUEST_TIMEOUT, problem(REQUEST_TIMEOUT, 0)],
    ["the unknown fallback", problem("something-new", 500)],
  ];

  it("gives every case of the wire contract its own message", () => {
    const messages = cases.map(([, p]) => imageProblemMessage(p));

    expect(new Set(messages).size).toBe(cases.length);
    for (const message of messages) expect(message).not.toBe("");
  });

  it.each(cases)("maps %s to English copy, never the server's title", (_label, p) => {
    const message = imageProblemMessage(p);

    expect(message).not.toContain("Server title");
    expect(message).toMatch(/^[A-Z]/);
  });

  it("routes an image type on its type, whatever the status", () => {
    expect(imageProblemMessage(problem(ImageProblemType.TOO_LARGE, 500))).toBe(
      imageProblemMessage(problem(ImageProblemType.TOO_LARGE, 413)),
    );
  });

  it("routes a not-found on status, since each owner mints its own type", () => {
    expect(imageProblemMessage(problem("bank-not-found", 404))).toBe(
      imageProblemMessage(problem("identity-user-not-found", 404)),
    );
  });

  it("states the wait of a throttled response when the server gave one", () => {
    expect(imageProblemMessage(problem("rate-limited", 429, { retryAfterSeconds: 17 }))).toContain(
      "17 seconds",
    );
    expect(imageProblemMessage(problem("rate-limited", 429, { retryAfterSeconds: 1 }))).toContain(
      "1 second ",
    );
  });

  it("asks for a moment, with no number, when it gave none", () => {
    const message = imageProblemMessage(problem("rate-limited", 429));

    expect(message).toContain("Wait a moment");
    expect(message).not.toMatch(/\d/);
  });

  it("ignores a wait that is not a positive whole number", () => {
    for (const value of [0, -3, 2.5, "9", null]) {
      expect(
        imageProblemMessage(problem("rate-limited", 429, { retryAfterSeconds: value })),
      ).toContain("Wait a moment");
    }
  });

  it("words the fallback for the action that failed", () => {
    const unknown = problem("something-new", 500);

    expect(imageProblemMessage(unknown)).toBe("The image could not be saved. Try again.");
    expect(imageProblemMessage(unknown, ImageAction.SAVE)).toBe(
      "The image could not be saved. Try again.",
    );
    expect(imageProblemMessage(unknown, ImageAction.REMOVE)).toBe(
      "The image could not be removed. Try again.",
    );
  });

  it.each([
    [411, ImageProblemType.LENGTH_REQUIRED],
    [413, ImageProblemType.TOO_LARGE],
    [415, ImageProblemType.UNSUPPORTED_TYPE],
    [422, ImageProblemType.INVALID],
  ])("gives a bodyless %i from an edge or proxy the message of its image type", (status, type) => {
    expect(imageProblemMessage(problem("about:blank", status))).toBe(
      imageProblemMessage(problem(type, status)),
    );
  });

  it.each(["constructor", "toString", "__proto__", "hasOwnProperty"])(
    "treats the inherited property name %j as an unknown type, not a message",
    (type) => {
      expect(imageProblemMessage(problem(type, 500))).toBe(
        "The image could not be saved. Try again.",
      );
    },
  );

  it("says the 415 covers a type that does not match the file, not only an unsupported format", () => {
    const message = imageProblemMessage(problem(ImageProblemType.UNSUPPORTED_TYPE, 415));

    expect(message).toContain("does not match its file type");
    expect(message).toContain("JPEG, PNG, WebP or GIF");
  });
});
