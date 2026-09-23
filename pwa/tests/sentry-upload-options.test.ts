import { describe, expect, it } from "vitest";
import { sentryUploadOptions } from "../sentry-upload-options";

describe("sentryUploadOptions", () => {
  it("keeps upload off with an empty authToken and no warning when there is no token", () => {
    expect(sentryUploadOptions({})).toEqual({
      options: { authToken: "" },
      uploadsSourcemaps: false,
      warning: null,
    });
  });

  it("uploads with a personal token and an org, to the per-environment project", () => {
    expect(
      sentryUploadOptions({
        SENTRY_AUTH_TOKEN: "sntryu_abc",
        SENTRY_ORG: "acme",
        NEXT_PUBLIC_APP_ENV: "prod",
      }),
    ).toEqual({
      options: { authToken: "sntryu_abc", org: "acme", project: "erpify-pwa-prod" },
      uploadsSourcemaps: true,
      warning: null,
    });
  });

  it("keeps upload off and warns, without naming the token, for a personal token with no org", () => {
    const result = sentryUploadOptions({ SENTRY_AUTH_TOKEN: "sntryu_abc" });
    expect(result.options).toEqual({ authToken: "" });
    expect(result.uploadsSourcemaps).toBe(false);
    expect(result.warning).toContain("SENTRY_ORG is empty");
    expect(result.warning).not.toContain("sntryu_abc");
  });

  it("uploads with an org-scoped token alone and passes no org key", () => {
    const result = sentryUploadOptions({ SENTRY_AUTH_TOKEN: "sntrys_eyJhIjoxfQ==_abc" });
    expect(result.options).toEqual({
      authToken: "sntrys_eyJhIjoxfQ==_abc",
      project: "erpify-pwa-dev",
    });
    expect(result.options).not.toHaveProperty("org");
    expect(result.uploadsSourcemaps).toBe(true);
    expect(result.warning).toBeNull();
  });

  it("treats a whitespace-only token as absent", () => {
    expect(sentryUploadOptions({ SENTRY_AUTH_TOKEN: " \n\t", SENTRY_ORG: "acme" })).toEqual({
      options: { authToken: "" },
      uploadsSourcemaps: false,
      warning: null,
    });
  });

  it("trims the token, and treats a whitespace-only org as absent", () => {
    const result = sentryUploadOptions({ SENTRY_AUTH_TOKEN: " sntryu_abc\n", SENTRY_ORG: "  " });
    expect(result.uploadsSourcemaps).toBe(false);
    expect(result.warning).not.toBeNull();

    const orgScoped = sentryUploadOptions({ SENTRY_AUTH_TOKEN: " sntrys_x\n", SENTRY_ORG: " " });
    expect(orgScoped.options).toEqual({ authToken: "sntrys_x", project: "erpify-pwa-dev" });
  });

  it("trims the org and lets an explicit project override the derived one", () => {
    expect(
      sentryUploadOptions({
        SENTRY_AUTH_TOKEN: "sntryu_abc",
        SENTRY_ORG: " acme ",
        SENTRY_PROJECT: " custom ",
        NEXT_PUBLIC_APP_ENV: "prod",
      }).options,
    ).toEqual({ authToken: "sntryu_abc", org: "acme", project: "custom" });
  });
});
