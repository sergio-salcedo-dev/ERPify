import { afterEach, describe, expect, it, vi } from "vitest";
import { imageReadUrl } from "@/context/shared/images/infrastructure/imageReadUrl";

describe("imageReadUrl", () => {
  afterEach(() => {
    vi.unstubAllEnvs();
  });

  it("composes the shared read route with the id", () => {
    vi.stubEnv("NEXT_PUBLIC_API_BASE_URL", "");

    expect(imageReadUrl("0190e2e5-5050-7050-8050-000000000001")).toBe(
      "/api/v1/images/0190e2e5-5050-7050-8050-000000000001",
    );
  });

  it("percent-encodes the id as one path segment", () => {
    vi.stubEnv("NEXT_PUBLIC_API_BASE_URL", "");

    expect(imageReadUrl("a/b?c#d")).toBe("/api/v1/images/a%2Fb%3Fc%23d");
  });

  it("honours the explicit cross-origin base, without a doubled slash", () => {
    vi.stubEnv("NEXT_PUBLIC_API_BASE_URL", "https://api.example.test/");

    expect(imageReadUrl("img")).toBe("https://api.example.test/api/v1/images/img");
  });

  it("refuses a base that makes the URL an active scheme", () => {
    vi.stubEnv("NEXT_PUBLIC_API_BASE_URL", "javascript:alert(1)//");

    expect(imageReadUrl("img")).toBeNull();
  });

  it.each([null, undefined, "", "   "])("is null when the owner holds %j", (id) => {
    expect(imageReadUrl(id)).toBeNull();
  });
});
