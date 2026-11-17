import { describe, expect, it, vi } from "vitest";
import type { HttpClient } from "@/context/shared/http-client/domain/HttpClient";
import { HttpError } from "@/context/shared/http-client/domain/HttpError";
import { ApiImageUploader } from "@/context/shared/images/infrastructure/ApiImageUploader";

function httpClient(overrides: Partial<HttpClient> = {}): HttpClient {
  return {
    get: vi.fn(),
    post: vi.fn(),
    put: vi.fn(),
    patch: vi.fn(),
    putRaw: vi.fn(),
    delete: vi.fn().mockResolvedValue(undefined),
    ...overrides,
  };
}

const URL = "/api/v1/backoffice/banks/b/logo";
const file = new File([new Uint8Array([1])], "a.png", { type: "image/png" });

describe("ApiImageUploader", () => {
  it("PUTs the file's bytes to the consumer's own URL and returns the id", async () => {
    const putRaw = vi.fn().mockResolvedValue({ imageId: "img-9" });

    const uploaded = await new ApiImageUploader(httpClient({ putRaw })).upload(URL, file);

    expect(uploaded).toEqual({ imageId: "img-9" });
    const [url, body, , options] = putRaw.mock.calls[0];
    expect(url).toBe(URL);
    expect(body).toBe(file);
    expect(options.timeoutMs).toBeGreaterThan(30_000);
  });

  it("guards the 2xx body: only { imageId: non-empty string } is accepted", async () => {
    const putRaw = vi.fn().mockResolvedValue({ imageId: "img-9" });
    await new ApiImageUploader(httpClient({ putRaw })).upload(URL, file);
    const guard = putRaw.mock.calls[0][2] as (body: unknown) => boolean;

    expect(guard({ imageId: "img-9" })).toBe(true);
    expect(guard({ imageId: "" })).toBe(false);
    expect(guard({ imageId: 7 })).toBe(false);
    expect(guard({})).toBe(false);
    expect(guard(null)).toBe(false);
    expect(guard("img-9")).toBe(false);
  });

  it("lets a rejection reach the caller untouched", async () => {
    const failure = new HttpError({
      type: "image-too-large",
      title: "t",
      status: 413,
      instance: "i",
      "correlation-id": "c",
    });
    const putRaw = vi.fn().mockRejectedValue(failure);

    await expect(new ApiImageUploader(httpClient({ putRaw })).upload(URL, file)).rejects.toBe(
      failure,
    );
  });

  it("DELETEs the consumer's URL", async () => {
    const del = vi.fn().mockResolvedValue(undefined);

    await new ApiImageUploader(httpClient({ delete: del })).remove(URL);

    expect(del).toHaveBeenCalledWith(URL);
  });
});
