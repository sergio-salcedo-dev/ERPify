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

  it("revokeCurrent() POSTs to the revoke-current endpoint with no payload", async () => {
    const post = vi.fn().mockResolvedValue(undefined);

    await new ApiSessionsRepository(httpClient({ post })).revokeCurrent();

    // No budget asked for, no transport options sent: the client's own default applies, and
    // the adapter must not invent a tighter one on the caller's behalf.
    expect(post).toHaveBeenCalledWith(
      API_ENDPOINTS.IDENTITY.SESSIONS_REVOKE_CURRENT,
      undefined,
      undefined,
      undefined,
    );
  });

  it("revokeCurrent() hands the caller's budget to the transport", async () => {
    const post = vi.fn().mockResolvedValue(undefined);

    await new ApiSessionsRepository(httpClient({ post })).revokeCurrent(1_500);

    // The port speaks in intent (a number of milliseconds) and this adapter is what turns it
    // into a transport option. Without this the budget would arrive nowhere and the sign-out
    // that supplies it would be bounded by nothing — the exact failure the caller's own timer
    // used to paper over.
    expect(post).toHaveBeenCalledWith(
      API_ENDPOINTS.IDENTITY.SESSIONS_REVOKE_CURRENT,
      undefined,
      undefined,
      { timeoutMs: 1_500 },
    );
  });
});
