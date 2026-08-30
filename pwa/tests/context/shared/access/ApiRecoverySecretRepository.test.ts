import { describe, expect, it, vi } from "vitest";
import { ApiRecoverySecretRepository } from "@/context/shared/access/infrastructure/ApiRecoverySecretRepository";
import { API_ENDPOINTS } from "@/context/shared/http-client/infrastructure/ApiEndpoints";
import type { HttpClient } from "@/context/shared/http-client/domain/HttpClient";

const MINTED_AT = "2026-08-01T09:00:00.000Z";
const EXPIRES_AT = "2036-08-01T09:00:00.000Z";

/** What the adapter hands the transport as the 2xx body check for a given call. */
type CapturedGuard = (body: unknown) => boolean;

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

/**
 * The two guards are module-private, and reaching them through an export would be testing a
 * seam the adapter does not have. They are observable where they matter instead — as the
 * argument the adapter passes the transport — so what these cases pin is the guard that a real
 * response is actually measured against, not a copy of it.
 */
async function statusGuard(): Promise<CapturedGuard> {
  const get = vi
    .fn()
    .mockResolvedValue({ data: { exists: false, mintedAt: null, expiresAt: null } });
  await new ApiRecoverySecretRepository(httpClient({ get })).read();
  return get.mock.calls[0][1] as CapturedGuard;
}

async function mintedGuard(): Promise<CapturedGuard> {
  const post = vi
    .fn()
    .mockResolvedValue({ data: { secret: "sel.ver", mintedAt: MINTED_AT, expiresAt: EXPIRES_AT } });
  await new ApiRecoverySecretRepository(httpClient({ post })).mint("s3cr3t");
  return post.mock.calls[0][2] as CapturedGuard;
}

describe("ApiRecoverySecretRepository", () => {
  it("read() asks the status path and hands back the envelope's data untouched", async () => {
    const status = { exists: false, mintedAt: null, expiresAt: null };
    const get = vi.fn().mockResolvedValue({ data: status });

    const result = await new ApiRecoverySecretRepository(httpClient({ get })).read();

    expect(result).toBe(status);
    expect(get).toHaveBeenCalledWith(API_ENDPOINTS.IDENTITY.RECOVERY_SECRET, expect.any(Function));
  });

  it("mint() posts the current password to the status path and returns the once-only body", async () => {
    const minted = { secret: "sel.ver", mintedAt: MINTED_AT, expiresAt: EXPIRES_AT };
    const post = vi.fn().mockResolvedValue({ data: minted });

    const result = await new ApiRecoverySecretRepository(httpClient({ post })).mint("s3cr3t");

    expect(result).toBe(minted);
    // The proof rides in the body for the same reason the revoke's does: a path or a query
    // string is kept by every access log in front of the application.
    expect(post).toHaveBeenCalledWith(
      API_ENDPOINTS.IDENTITY.RECOVERY_SECRET,
      { currentPassword: "s3cr3t" },
      expect.any(Function),
    );
  });

  it("mint() lets the transport's rejection through, so the form routes on the problem type", async () => {
    const rejection = new Error("403");
    const post = vi.fn().mockRejectedValue(rejection);

    await expect(new ApiRecoverySecretRepository(httpClient({ post })).mint("wrong")).rejects.toBe(
      rejection,
    );
  });

  it("revoke() posts the current password to the revoke path", async () => {
    const post = vi.fn().mockResolvedValue(undefined);
    const remove = vi.fn();

    await new ApiRecoverySecretRepository(httpClient({ post, delete: remove })).revoke("s3cr3t");

    // The credential rides in the body, which is the whole reason this is a POST on a path of
    // its own: a DELETE would have to carry the proof in the URL, where the access log keeps it.
    expect(post).toHaveBeenCalledWith(API_ENDPOINTS.IDENTITY.RECOVERY_SECRET_REVOKE, {
      currentPassword: "s3cr3t",
    });
    expect(remove).not.toHaveBeenCalled();
  });

  it("revoke() asks for no response guard, so an empty 204 is not a malformed envelope", async () => {
    const post = vi.fn().mockResolvedValue(undefined);

    await new ApiRecoverySecretRepository(httpClient({ post })).revoke("s3cr3t");

    expect(post.mock.calls[0]).toHaveLength(2);
  });

  it("revoke() never targets the path that reads and mints the secret", () => {
    expect(API_ENDPOINTS.IDENTITY.RECOVERY_SECRET_REVOKE).not.toBe(
      API_ENDPOINTS.IDENTITY.RECOVERY_SECRET,
    );
  });

  it("lets the transport's rejection through untouched, so the caller routes on the problem type", async () => {
    const rejection = new Error("403");
    const post = vi.fn().mockRejectedValue(rejection);

    await expect(
      new ApiRecoverySecretRepository(httpClient({ post })).revoke("wrong"),
    ).rejects.toBe(rejection);
  });
});

/**
 * The guard is what turns an impossible body into a `malformed-response-envelope` instead of a
 * rendered one. It is checked against `exists` rather than accepting either shape, and that
 * correlation is the load-bearing half: without it a body claiming a secret while saying
 * nothing about when it dies flows through to a surface that states the expiry unconditionally,
 * and the holder reads a blank where the date should be.
 */
describe("ApiRecoverySecretRepository — the status envelope guard", () => {
  it("refuses a body that claims a secret without saying when it was minted or when it dies", async () => {
    const guard = await statusGuard();

    expect(guard({ data: { exists: true, mintedAt: MINTED_AT } })).toBe(false);
    expect(guard({ data: { exists: true, expiresAt: EXPIRES_AT } })).toBe(false);
    expect(guard({ data: { exists: true, mintedAt: MINTED_AT, expiresAt: null } })).toBe(false);
    expect(guard({ data: { exists: true, mintedAt: null, expiresAt: EXPIRES_AT } })).toBe(false);
  });

  it("refuses a body that denies a secret while carrying its instants anyway", async () => {
    const guard = await statusGuard();

    // The two states are exclusive on the wire; admitting the contradiction would let the
    // surface pick which half of the body to believe.
    expect(guard({ data: { exists: false, mintedAt: MINTED_AT, expiresAt: EXPIRES_AT } })).toBe(
      false,
    );
  });

  it.each([
    {
      label: "a secret with both instants",
      body: { data: { exists: true, mintedAt: MINTED_AT, expiresAt: EXPIRES_AT } },
    },
    {
      label: "no secret, both instants null",
      body: { data: { exists: false, mintedAt: null, expiresAt: null } },
    },
  ])("admits $label", async ({ body }) => {
    expect((await statusGuard())(body)).toBe(true);
  });

  it.each([
    { label: "null", body: null },
    { label: "a bare string", body: "no secret here" },
    { label: "an envelope with no data member", body: {} },
    { label: "an envelope whose data is null", body: { data: null } },
    { label: "an envelope whose data is a string", body: { data: "text" } },
    {
      label: "a data object with no exists flag",
      body: { data: { mintedAt: null, expiresAt: null } },
    },
    {
      label: "an exists flag that is not a boolean",
      body: { data: { exists: "true", mintedAt: null, expiresAt: null } },
    },
  ])("refuses $label", async ({ body }) => {
    expect((await statusGuard())(body)).toBe(false);
  });
});

describe("ApiRecoverySecretRepository — the minted envelope guard", () => {
  it("admits the once-only body, the only response that ever carries the plaintext", async () => {
    const guard = await mintedGuard();

    expect(guard({ data: { secret: "sel.ver", mintedAt: MINTED_AT, expiresAt: EXPIRES_AT } })).toBe(
      true,
    );
  });

  it.each([
    { label: "null", body: null },
    { label: "an envelope with no data member", body: {} },
    { label: "an envelope whose data is null", body: { data: null } },
    {
      label: "a mint that returned no secret",
      body: { data: { mintedAt: MINTED_AT, expiresAt: EXPIRES_AT } },
    },
    {
      label: "a secret that is not a string",
      body: { data: { secret: 42, mintedAt: MINTED_AT, expiresAt: EXPIRES_AT } },
    },
    {
      label: "a mint with no minted instant",
      body: { data: { secret: "sel.ver", expiresAt: EXPIRES_AT } },
    },
    // The plaintext is shown once and never again, so a mint whose expiry is missing is a
    // credential the holder can neither read back nor plan around.
    { label: "a mint with no expiry", body: { data: { secret: "sel.ver", mintedAt: MINTED_AT } } },
  ])("refuses $label", async ({ body }) => {
    expect((await mintedGuard())(body)).toBe(false);
  });
});
