import { describe, expect, it, vi } from "vitest";
import { ApiSessionsRepository } from "@/context/shared/access/infrastructure/ApiSessionsRepository";
import { API_ENDPOINTS } from "@/context/shared/http-client/infrastructure/ApiEndpoints";
import type { HttpClient } from "@/context/shared/http-client/domain/HttpClient";
import type { SessionSummary } from "@/context/shared/access/domain/SessionSummary";

function httpClient(overrides: Partial<HttpClient>): HttpClient {
  return {
    get: vi.fn(),
    post: vi.fn(),
    put: vi.fn(),
    patch: vi.fn(),
    delete: vi.fn(),
    ...overrides,
  };
}

const ROWS: SessionSummary[] = [
  { id: "s-1", device: "Chrome on macOS", createdAt: "2026-07-10T09:00:00.000Z", current: true },
  { id: "s-2", device: "Safari on iPhone", createdAt: "2026-07-09T18:30:00.000Z", current: false },
];

describe("ApiSessionsRepository", () => {
  it("list() unwraps the {data:[...]} envelope from GET /sessions", async () => {
    const get = vi.fn().mockResolvedValue({ data: ROWS });

    const sessions = await new ApiSessionsRepository(httpClient({ get })).list();

    expect(get).toHaveBeenCalledWith(API_ENDPOINTS.IDENTITY.SESSIONS, expect.any(Function));
    expect(sessions).toEqual(ROWS);
  });

  it("revokeOthers() POSTs to the revoke-others endpoint with no payload", async () => {
    const post = vi.fn().mockResolvedValue(undefined);

    await new ApiSessionsRepository(httpClient({ post })).revokeOthers();

    expect(post).toHaveBeenCalledWith(API_ENDPOINTS.IDENTITY.SESSIONS_REVOKE_OTHERS, undefined);
  });
});
