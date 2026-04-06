import { describe, expect, it, vi, afterEach } from "vitest";
import {
  fetchFrankenPhpHotReloadSubscribeUrl,
  joinPublicOrigin,
} from "@/lib/frankenphp-hot-reload";

describe("joinPublicOrigin", () => {
  it("joins base without trailing slash and path with leading slash", () => {
    expect(joinPublicOrigin("https://localhost", "/.well-known/mercure?topic=x")).toBe(
      "https://localhost/.well-known/mercure?topic=x",
    );
  });

  it("strips trailing slash from base", () => {
    expect(joinPublicOrigin("https://localhost/", "/path")).toBe("https://localhost/path");
  });

  it("adds leading slash to path when missing", () => {
    expect(joinPublicOrigin("https://localhost", "path")).toBe("https://localhost/path");
  });
});

describe("fetchFrankenPhpHotReloadSubscribeUrl", () => {
  afterEach(() => {
    vi.unstubAllEnvs();
    vi.restoreAllMocks();
  });

  it("returns null when not in development", async () => {
    vi.stubEnv("NODE_ENV", "production");
    const url = await fetchFrankenPhpHotReloadSubscribeUrl();
    expect(url).toBeNull();
  });

  it("returns null when internal or public base URL is missing", async () => {
    vi.stubEnv("NODE_ENV", "development");
    vi.stubEnv("SYMFONY_INTERNAL_URL", "");
    vi.stubEnv("NEXT_PUBLIC_SYMFONY_API_BASE_URL", "https://localhost");
    expect(await fetchFrankenPhpHotReloadSubscribeUrl()).toBeNull();

    vi.stubEnv("SYMFONY_INTERNAL_URL", "http://php:80");
    vi.stubEnv("NEXT_PUBLIC_SYMFONY_API_BASE_URL", "");
    expect(await fetchFrankenPhpHotReloadSubscribeUrl()).toBeNull();
  });

  it("returns subscribe URL when API responds enabled", async () => {
    vi.stubEnv("NODE_ENV", "development");
    vi.stubEnv("SYMFONY_INTERNAL_URL", "http://php:80");
    vi.stubEnv("NEXT_PUBLIC_SYMFONY_API_BASE_URL", "https://localhost");

    global.fetch = vi.fn().mockResolvedValue({
      ok: true,
      json: () =>
        Promise.resolve({
          enabled: true,
          subscribePath: "/.well-known/mercure?topic=test",
        }),
    });

    const url = await fetchFrankenPhpHotReloadSubscribeUrl();
    expect(url).toBe("https://localhost/.well-known/mercure?topic=test");
    expect(global.fetch).toHaveBeenCalledWith("http://php:80/api/v1/dev/frankenphp-hot-reload", {
      cache: "no-store",
    });
  });

  it("returns null when API responds disabled", async () => {
    vi.stubEnv("NODE_ENV", "development");
    vi.stubEnv("SYMFONY_INTERNAL_URL", "http://php:80");
    vi.stubEnv("NEXT_PUBLIC_SYMFONY_API_BASE_URL", "https://localhost");

    global.fetch = vi.fn().mockResolvedValue({
      ok: true,
      json: () => Promise.resolve({ enabled: false }),
    });

    expect(await fetchFrankenPhpHotReloadSubscribeUrl()).toBeNull();
  });
});
