import { afterEach, beforeEach, describe, expect, it, vi, type MockInstance } from "vitest";
import { FetchHttpClient } from "@/context/shared/http-client/infrastructure/FetchHttpClient";
import { NETWORK_ERROR, REQUEST_TIMEOUT } from "@/context/shared/http-client/domain/HttpClient";
import { HttpError } from "@/context/shared/http-client/domain/HttpError";
import { HttpStatus } from "@/context/shared/http-client/domain/HttpStatus";
import { Routes } from "@/context/shared/routing/domain/Routes";

const WRITE_URL = "/api/v1/backoffice/banks/11111111-1111-4111-8111-111111111111/logo";

function problemBody(type: string, status: number): Record<string, unknown> {
  return {
    type,
    title: `Title of ${type}`,
    status,
    instance: "01H-instance",
    "correlation-id": "01H-correlation",
  };
}

const PNG_BYTES = [0x89, 0x50, 0x4e, 0x47];

function readBytes(blob: Blob): Promise<ArrayBuffer> {
  return new Promise((resolve, reject) => {
    const reader = new FileReader();
    reader.onload = () => resolve(reader.result as ArrayBuffer);
    reader.onerror = () => reject(reader.error);
    reader.readAsArrayBuffer(blob);
  });
}

function json(status: number, body: unknown, headers: Record<string, string> = {}): Response {
  return new Response(JSON.stringify(body), {
    status,
    headers: { "Content-Type": "application/json", ...headers },
  });
}

function pngFile(): File {
  return new File([new Uint8Array(PNG_BYTES)], "logo.png", { type: "image/png" });
}

function sentInit(spy: MockInstance): RequestInit {
  return spy.mock.calls[0][1] as RequestInit;
}

describe("FetchHttpClient.putRaw", () => {
  let fetchSpy: MockInstance;

  beforeEach(() => {
    fetchSpy = vi.spyOn(globalThis, "fetch");
  });

  afterEach(() => {
    fetchSpy.mockRestore();
  });

  it("sends the file itself as a PUT body, never a JSON encoding of it", async () => {
    fetchSpy.mockResolvedValueOnce(json(HttpStatus.OK, { imageId: "img-1" }));
    const file = pngFile();

    await new FetchHttpClient().putRaw(WRITE_URL, file);

    const init = sentInit(fetchSpy);
    expect(init.method).toBe("PUT");
    expect(init.body).toBe(file);
  });

  it("sends a body carrying the file's bytes and size, not just the same object", async () => {
    fetchSpy.mockResolvedValueOnce(json(HttpStatus.OK, { imageId: "img-1" }));

    await new FetchHttpClient().putRaw(WRITE_URL, pngFile());

    const body = sentInit(fetchSpy).body as Blob;
    expect(body.size).toBe(PNG_BYTES.length);
    expect(body.type).toBe("image/png");
    expect([...new Uint8Array(await readBytes(body))]).toEqual(PNG_BYTES);
  });

  it("sets no Content-Type, so the browser derives it from the blob and fills Content-Length", async () => {
    fetchSpy.mockResolvedValueOnce(json(HttpStatus.OK, { imageId: "img-1" }));

    await new FetchHttpClient().putRaw(WRITE_URL, pngFile());

    const headers = new Headers(sentInit(fetchSpy).headers);
    expect(headers.has("Content-Type")).toBe(false);
    expect(headers.get("Accept")).toBe("application/json");
  });

  it("returns the guarded 2xx body", async () => {
    fetchSpy.mockResolvedValueOnce(json(HttpStatus.OK, { imageId: "img-1" }));

    const body = await new FetchHttpClient().putRaw<{ imageId: string }>(WRITE_URL, pngFile());

    expect(body).toEqual({ imageId: "img-1" });
  });

  it("mints a malformed-response-envelope when the 2xx body fails its guard", async () => {
    fetchSpy.mockResolvedValueOnce(json(HttpStatus.OK, { nope: true }));

    await expect(
      new FetchHttpClient().putRaw(
        WRITE_URL,
        pngFile(),
        (_body): _body is { imageId: string } => false,
      ),
    ).rejects.toMatchObject({ problem: { type: "malformed-response-envelope" } });
  });

  it.each([
    [411, "image-length-required"],
    [413, "image-too-large"],
    [415, "image-unsupported-type"],
    [HttpStatus.UNPROCESSABLE_ENTITY, "image-invalid"],
  ])("surfaces a %i as an HttpError carrying the Problem Details", async (status, type) => {
    fetchSpy.mockResolvedValueOnce(json(status, problemBody(type, status)));

    const failure = await new FetchHttpClient().putRaw(WRITE_URL, pngFile()).catch((e) => e);

    expect(failure).toBeInstanceOf(HttpError);
    expect((failure as HttpError).problem).toMatchObject({ type, status });
  });

  it("mints network-error when the request never reaches the server", async () => {
    fetchSpy.mockRejectedValueOnce(new TypeError("Failed to fetch"));

    await expect(new FetchHttpClient().putRaw(WRITE_URL, pngFile())).rejects.toMatchObject({
      problem: { type: NETWORK_ERROR, status: 0 },
    });
  });

  describe("timeout", () => {
    beforeEach(() => {
      vi.useFakeTimers();
    });

    afterEach(() => {
      vi.useRealTimers();
    });

    it("gives up after the caller's budget with request-timeout", async () => {
      fetchSpy.mockImplementation(
        (_input: RequestInfo | URL, init?: RequestInit) =>
          new Promise((_resolve, reject) => {
            init?.signal?.addEventListener("abort", () => {
              reject(new DOMException("The operation was aborted.", "AbortError"));
            });
          }),
      );
      const pending = new FetchHttpClient().putRaw(WRITE_URL, pngFile(), undefined, {
        timeoutMs: 250,
      });
      const settled = expect(pending).rejects.toMatchObject({
        problem: { type: REQUEST_TIMEOUT, status: 0 },
      });

      await vi.advanceTimersByTimeAsync(250);

      await settled;
    });
  });
});

describe("FetchHttpClient.putRaw session expiry", () => {
  let fetchSpy: MockInstance;
  const replace = vi.fn();

  beforeEach(() => {
    vi.resetModules();
    replace.mockReset();
    vi.stubGlobal("location", {
      pathname: "/backoffice/dev-tools/image-upload",
      search: "",
      replace,
      assign: vi.fn(),
    });
    fetchSpy = vi.spyOn(globalThis, "fetch");
  });

  afterEach(() => {
    fetchSpy.mockRestore();
    vi.unstubAllGlobals();
  });

  it("bounces to /login on a 401 exactly as the JSON verbs do", async () => {
    fetchSpy.mockImplementation(() =>
      Promise.resolve(json(HttpStatus.UNAUTHORIZED, problemBody("session-expired", 401))),
    );
    // Fresh module: the single-flight bounce guard is module state.
    const { FetchHttpClient: Fresh } =
      await import("@/context/shared/http-client/infrastructure/FetchHttpClient");

    await expect(new Fresh().putRaw(WRITE_URL, pngFile())).rejects.toMatchObject({
      problem: { status: HttpStatus.UNAUTHORIZED },
    });

    expect(replace).toHaveBeenCalledTimes(1);
    expect(replace).toHaveBeenCalledWith(
      `/login?next=${encodeURIComponent("/backoffice/dev-tools/image-upload")}&reason=session-expired`,
    );
    expect(Routes.LOGIN).toBe("/login");
  });
});
