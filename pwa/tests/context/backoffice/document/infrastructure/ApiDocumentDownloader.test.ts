import { afterEach, describe, expect, it, vi } from "vitest";
import { ApiDocumentDownloader } from "@/context/backoffice/document/infrastructure/ApiDocumentDownloader";
import { NETWORK_ERROR } from "@/context/shared/http-client/domain/HttpClient";
import { HttpError } from "@/context/shared/http-client/domain/HttpError";

const DOCUMENT_ID = "0190e9c2-7b5a-7d40-9c8f-2f9b5d3e1c01";

describe("ApiDocumentDownloader", () => {
  afterEach(() => {
    vi.unstubAllGlobals();
  });

  it("reads the original of the audited read route as bytes, same-origin and uncached", async () => {
    const fetchMock = vi.fn().mockResolvedValue(new Response(new Uint8Array([1, 2, 3])));
    vi.stubGlobal("fetch", fetchMock);

    const blob = await new ApiDocumentDownloader().download(DOCUMENT_ID);

    expect(new Uint8Array(await blob.arrayBuffer())).toEqual(new Uint8Array([1, 2, 3]));
    const [url, init] = fetchMock.mock.calls[0];
    expect(url).toBe(`/api/v1/documents/${DOCUMENT_ID}/content`);
    expect(init).toMatchObject({ method: "GET", credentials: "same-origin", cache: "no-store" });
  });

  it("turns a refusal into the server's Problem Details and saves nothing", async () => {
    const problem = {
      type: "gone",
      title: "The document is gone.",
      status: 410,
      instance: "i",
      "correlation-id": "c",
    };
    vi.stubGlobal(
      "fetch",
      vi.fn().mockResolvedValue(new Response(JSON.stringify(problem), { status: 410 })),
    );

    const download = new ApiDocumentDownloader().download(DOCUMENT_ID);

    await expect(download).rejects.toBeInstanceOf(HttpError);
    await expect(download).rejects.toMatchObject({ problem: { type: "gone", status: 410 } });
  });

  it("reports an unreachable server as a network error", async () => {
    vi.stubGlobal("fetch", vi.fn().mockRejectedValue(new TypeError("Failed to fetch")));

    await expect(new ApiDocumentDownloader().download(DOCUMENT_ID)).rejects.toMatchObject({
      problem: { type: NETWORK_ERROR, status: 0 },
    });
  });
});
