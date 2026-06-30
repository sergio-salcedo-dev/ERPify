import { describe, expect, it, vi } from "vitest";
import { ApiAuditTimelineNavigator } from "@/context/backoffice/audit/infrastructure/ApiAuditTimelineNavigator";
import type { HttpClient } from "@/context/shared/http-client/domain/HttpClient";

const envelope = {
  data: [
    {
      id: "019f0691-2b5b-731e-9509-3470576159d6",
      occurredOn: "2026-06-27T00:53:24.955168+00:00",
      level: "security",
      action: "ACCESS_DENIED",
      actorType: "api_key",
      actorId: "019f0427-61f7-71d6-ae8f-b91d41227c29",
      correlationId: "019f0691-2aeb-7377-ba2d-1c9666c9ab90",
      resourceType: "BankAccount",
      resourceId: "019f0360-f3a4-7864-b0cc-0d41a56bf855",
      actorErased: false,
    },
  ],
  pagination: {
    hasNext: false,
    hasPrev: true,
    count: null,
    links: { next: null, prev: "/api/v1/backoffice/audit/timeline?limit=25&before=opaque" },
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

describe("ApiAuditTimelineNavigator.follow", () => {
  it("fetches a server-issued relative link VERBATIM and maps the envelope", async () => {
    const httpClient = httpClientReturning(envelope);
    const link = "/api/v1/backoffice/audit/timeline?limit=25&sort=occurredOn&after=opaque";

    const page = await new ApiAuditTimelineNavigator(httpClient).follow(link);

    expect(httpClient.get).toHaveBeenCalledTimes(1);
    expect(vi.mocked(httpClient.get).mock.calls[0][0]).toBe(link);
    expect(page.entries[0].action).toBe("ACCESS_DENIED");
    expect(page.hasPrev).toBe(true);
    expect(page.links.prev).toBe("/api/v1/backoffice/audit/timeline?limit=25&before=opaque");
  });

  it("refuses non-same-origin or unsafe links and never hits the network (open-redirect guard)", async () => {
    const httpClient = httpClientReturning(envelope);
    const navigator = new ApiAuditTimelineNavigator(httpClient);

    for (const bad of [
      "https://evil.example/api/v1/backoffice/audit/timeline?after=c",
      "//evil.example/api/v1/backoffice/audit/timeline",
      "api/v1/backoffice/audit/timeline?after=c",
      "javascript:alert(1)",
      "",
    ]) {
      await expect(navigator.follow(bad)).rejects.toThrow();
    }
    expect(httpClient.get).not.toHaveBeenCalled();
  });
});
