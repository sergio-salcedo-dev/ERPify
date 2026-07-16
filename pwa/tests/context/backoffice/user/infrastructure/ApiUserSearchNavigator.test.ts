import { describe, expect, it, vi } from "vitest";
import { ApiUserSearchNavigator } from "@/context/backoffice/user/infrastructure/ApiUserSearchNavigator";
import type { HttpClient } from "@/context/shared/http-client/domain/HttpClient";

const primitives = {
  id: "0190a1b2-c3d4-7e5f-8a9b-0c1d2e3f4a5b",
  email: "admin@erpify.test",
  status: "ACTIVE",
  roles: ["ADMIN"],
  createdAt: "2026-01-01T00:00:00+00:00",
  updatedAt: "2026-01-02T00:00:00+00:00",
};

const envelope = {
  data: [primitives],
  pagination: {
    hasNext: false,
    hasPrev: true,
    count: null,
    links: { next: null, prev: "/api/v1/backoffice/users?limit=25&before=opaque" },
  },
};

function httpClientReturning(response: unknown): HttpClient {
  return {
    get: vi.fn().mockResolvedValue(response),
    post: vi.fn(),
    put: vi.fn(),
    patch: vi.fn(),
    delete: vi.fn(),
  };
}

describe("ApiUserSearchNavigator.follow", () => {
  it("fetches a server-issued relative link VERBATIM and maps the envelope", async () => {
    const httpClient = httpClientReturning(envelope);
    const link = "/api/v1/backoffice/users?limit=25&sort=email&after=opaque-cursor";

    const page = await new ApiUserSearchNavigator(httpClient).follow(link);

    // The link is forwarded UNCHANGED — never parsed, decomposed or rebuilt. The
    // cursor only ever travels inside the server-composed link.
    expect(httpClient.get).toHaveBeenCalledTimes(1);
    expect(vi.mocked(httpClient.get).mock.calls[0][0]).toBe(link);
    expect(page.items[0].email).toBe("admin@erpify.test");
    expect(page.hasPrev).toBe(true);
    expect(page.links.prev).toBe("/api/v1/backoffice/users?limit=25&before=opaque");
  });

  it("refuses non-same-origin or unsafe links and never hits the network (open-redirect guard)", async () => {
    const httpClient = httpClientReturning(envelope);
    const navigator = new ApiUserSearchNavigator(httpClient);

    for (const bad of [
      "https://evil.example/api/v1/backoffice/users?after=c", // absolute, external host
      "//evil.example/api/v1/backoffice/users", // protocol-relative
      "api/v1/backoffice/users?after=c", // not path-absolute
      "javascript:alert(1)", // dangerous scheme
      "",
    ]) {
      await expect(navigator.follow(bad)).rejects.toThrow();
    }
    expect(httpClient.get).not.toHaveBeenCalled();
  });
});
