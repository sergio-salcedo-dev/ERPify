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
      options: {
        authToken: "sntryu_abc",
        org: "acme",
        project: "erpify-pwa-prod",
        release: { create: true },
      },
      uploadsSourcemaps: true,
      warning: null,
    });
  });

  it("keeps upload off and warns, without naming the token, for a personal token with no org", () => {
    const result = sentryUploadOptions({ SENTRY_AUTH_TOKEN: "sntryu_abc" });
    expect(result.options).toStrictEqual({ authToken: "" });
    expect(result.uploadsSourcemaps).toBe(false);
    expect(result.warning).toContain("SENTRY_ORG is empty");
    expect(result.warning).not.toContain("sntryu_abc");
  });

  it("uploads with an org-scoped token alone and passes no org key", () => {
    const result = sentryUploadOptions({ SENTRY_AUTH_TOKEN: "sntrys_eyJhIjoxfQ==_abc" });
    expect(result.options).toEqual({
      authToken: "sntrys_eyJhIjoxfQ==_abc",
      project: "erpify-pwa-dev",
      release: { create: true },
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
    expect(orgScoped.options).toEqual({
      authToken: "sntrys_x",
      project: "erpify-pwa-dev",
      release: { create: true },
    });
  });

  it("trims the org and lets an explicit project override the derived one", () => {
    expect(
      sentryUploadOptions({
        SENTRY_AUTH_TOKEN: "sntryu_abc",
        SENTRY_ORG: " acme ",
        SENTRY_PROJECT: " custom ",
        NEXT_PUBLIC_APP_ENV: "prod",
      }).options,
    ).toEqual({
      authToken: "sntryu_abc",
      org: "acme",
      project: "custom",
      release: { create: true },
    });
  });

  describe("release", () => {
    const SHA = "0123456789abcdef0123456789abcdef01234567";
    const UPLOAD = { SENTRY_AUTH_TOKEN: "sntrys_x", SENTRY_RELEASE: ` ${SHA}\n` };

    it("names the release with upload off, so the bundles still report it, and creates nothing", () => {
      expect(sentryUploadOptions({ SENTRY_RELEASE: SHA }).options).toEqual({
        authToken: "",
        release: { name: SHA, create: false },
      });
    });

    it("leaves release out with upload off and no name, so the SDK keeps its CI and git fallback", () => {
      expect(sentryUploadOptions({ SENTRY_RELEASE: "  " }).options).toStrictEqual({
        authToken: "",
      });
      expect(sentryUploadOptions({}).options).toStrictEqual({ authToken: "" });
    });

    it("leaves commit association to the plugin's default without a repository", () => {
      const result = sentryUploadOptions(UPLOAD);
      expect(result.options.release).toStrictEqual({ name: SHA, create: true });
      expect(result.warning).toBeNull();
    });

    it("associates the release with its commit when the repository is named", () => {
      const result = sentryUploadOptions({ ...UPLOAD, SENTRY_REPOSITORY: " acme/erpify " });
      expect(result.options.release).toEqual({
        name: SHA,
        create: true,
        setCommits: { repo: "acme/erpify", commit: SHA, ignoreMissing: true },
      });
      expect(result.warning).toBeNull();
    });

    it.each([
      ["no release", {}],
      ["a release that is not a SHA", { SENTRY_RELEASE: "1.2.0" }],
      ["an abbreviated SHA", { SENTRY_RELEASE: SHA.slice(0, 12) }],
      ["an upper-case SHA", { SENTRY_RELEASE: SHA.toUpperCase() }],
      ["an id between the two object formats", { SENTRY_RELEASE: `${SHA}0` }],
    ])("keeps association off and warns for a repository with %s", (_label, release) => {
      const result = sentryUploadOptions({
        SENTRY_AUTH_TOKEN: "sntrys_x",
        SENTRY_REPOSITORY: "acme/erpify",
        ...release,
      });
      expect(result.options.release).not.toHaveProperty("setCommits");
      expect(result.uploadsSourcemaps).toBe(true);
      expect(result.warning).toContain("SENTRY_RELEASE is not a full commit SHA");
      expect(result.warning).not.toContain("sntrys_x");
    });

    it("warns about a repository while upload is off, since association needs the token", () => {
      const result = sentryUploadOptions({ SENTRY_RELEASE: SHA, SENTRY_REPOSITORY: "acme/erpify" });
      expect(result.options).toStrictEqual({
        authToken: "",
        release: { name: SHA, create: false },
      });
      expect(result.uploadsSourcemaps).toBe(false);
      expect(result.warning).toContain("SENTRY_REPOSITORY is set but source-map upload is off");
    });

    it("reports both misconfigurations when a personal token lacks its org and a repository is set", () => {
      const warning = sentryUploadOptions({
        SENTRY_AUTH_TOKEN: "sntryu_abc",
        SENTRY_REPOSITORY: "acme/erpify",
      }).warning;
      expect(warning).toContain("SENTRY_ORG is empty");
      expect(warning).toContain("SENTRY_REPOSITORY is set but source-map upload is off");
      expect(warning).not.toContain("sntryu_abc");
    });

    it("treats a whitespace-only repository as absent", () => {
      const result = sentryUploadOptions({ ...UPLOAD, SENTRY_REPOSITORY: "  " });
      expect(result.options.release).toStrictEqual({ name: SHA, create: true });
      expect(result.warning).toBeNull();
    });

    it("creates an unnamed release when upload is on without a name, leaving the name to the SDK", () => {
      const result = sentryUploadOptions({
        SENTRY_AUTH_TOKEN: "sntrys_x",
        SENTRY_REPOSITORY: "acme/erpify",
      });
      expect(result.options.release).toStrictEqual({ create: true });
    });

    it("accepts a SHA-256 object id as a full commit", () => {
      const sha256 = SHA + SHA.slice(0, 24);
      const result = sentryUploadOptions({
        SENTRY_AUTH_TOKEN: "sntrys_x",
        SENTRY_RELEASE: sha256,
        SENTRY_REPOSITORY: "acme/erpify",
      });
      expect(result.options.release?.setCommits).toStrictEqual({
        repo: "acme/erpify",
        commit: sha256,
        ignoreMissing: true,
      });
      expect(result.warning).toBeNull();
    });
  });
});
