import { afterEach, beforeEach, describe, expect, it, vi } from "vitest";
import {
  CREDENTIAL_REPLACEMENT_WINDOW_MS,
  markCredentialReplaced,
  resetCredentialReplacementForTests,
  takeCredentialReplacement,
} from "@/context/shared/navigation/infrastructure/credentialReplacement";

const MARKER_KEY = "erpify:credential-replaced";

describe("credentialReplacement", () => {
  beforeEach(() => {
    localStorage.removeItem(MARKER_KEY);
    resetCredentialReplacementForTests();
  });

  afterEach(() => {
    vi.restoreAllMocks();
    localStorage.removeItem(MARKER_KEY);
  });

  it("reports nothing when no credential was replaced", () => {
    expect(takeCredentialReplacement()).toBe(false);
  });

  it("reports a fresh replacement once per document", () => {
    markCredentialReplaced(1_000);

    expect(takeCredentialReplacement(1_000 + 5_000)).toBe(true);
    expect(takeCredentialReplacement(1_000 + 6_000)).toBe(false);
  });

  it("lets a later replacement be acted on again", () => {
    markCredentialReplaced(1_000);
    expect(takeCredentialReplacement(2_000)).toBe(true);

    markCredentialReplaced(50_000);
    expect(takeCredentialReplacement(51_000)).toBe(true);
  });

  it("forgets a replacement older than the window", () => {
    markCredentialReplaced(1_000);

    expect(takeCredentialReplacement(1_000 + CREDENTIAL_REPLACEMENT_WINDOW_MS + 1)).toBe(false);
  });

  it("holds exactly the window", () => {
    markCredentialReplaced(1_000);

    expect(takeCredentialReplacement(1_000 + CREDENTIAL_REPLACEMENT_WINDOW_MS)).toBe(true);
  });

  it("ignores a marker that is not an instant", () => {
    localStorage.setItem(MARKER_KEY, "not-a-number");

    expect(takeCredentialReplacement()).toBe(false);
  });

  it("degrades to no replacement when storage refuses", () => {
    vi.spyOn(Storage.prototype, "setItem").mockImplementation(() => {
      throw new Error("blocked");
    });
    vi.spyOn(Storage.prototype, "getItem").mockImplementation(() => {
      throw new Error("blocked");
    });

    expect(() => markCredentialReplaced()).not.toThrow();
    expect(takeCredentialReplacement()).toBe(false);
  });

  it("writes an instant and nothing else", () => {
    markCredentialReplaced(1_234);

    expect(localStorage.getItem(MARKER_KEY)).toBe("1234");
  });
});
