import { afterEach, beforeEach, describe, expect, it, vi, type MockInstance } from "vitest";
import {
  FetchHttpClient,
  MALFORMED_RESPONSE_ENVELOPE,
} from "@/context/shared/infrastructure/HttpClient/HttpClient";
import { HttpError } from "@/context/shared/infrastructure/HttpClient/HttpError";
import { isProblemDetails } from "@/context/shared/domain/ProblemDetails";
import { HttpStatus } from "@/context/shared/domain/types/http";

// v7-shaped: ProblemDetails.instance / correlation-id are UUID v7, minted via @/lib/uuidV7.
const { STUB_UUID } = vi.hoisted(() => ({
  STUB_UUID: "00000000-0000-7000-8000-000000000000",
}));

vi.mock("@/lib/uuidV7", () => ({
  uuidV7: () => STUB_UUID,
}));

function makeResponse(
  status: number,
  body: unknown,
  init?: { contentType?: string; headers?: Record<string, string> },
): Response {
  const headers = new Headers({
    "Content-Type": init?.contentType ?? "application/json",
    ...init?.headers,
  });
  return new Response(body === undefined ? null : JSON.stringify(body), {
    status,
    headers,
  });
}

function urlOf(input: RequestInfo | URL): string {
  if (typeof input === "string") return input;
  if (input instanceof URL) return input.toString();
  return input.url;
}

describe("FetchHttpClient", () => {
  let fetchSpy: MockInstance;

  beforeEach(() => {
    fetchSpy = vi.spyOn(globalThis, "fetch");
  });

  afterEach(() => {
    fetchSpy.mockRestore();
  });

  it("passes through an RFC 9457 problem body verbatim on 4xx", async () => {
    const wireProblem = {
      type: "bank-not-found",
      title: "Bank not found.",
      status: HttpStatus.NOT_FOUND,
      instance: "01H-instance",
      "correlation-id": "01H-correlation",
    };
    fetchSpy.mockResolvedValueOnce(
      makeResponse(HttpStatus.NOT_FOUND, wireProblem, {
        contentType: "application/problem+json",
      }),
    );

    const client = new FetchHttpClient();

    await expect(client.get("/api/v1/backoffice/banks/missing")).rejects.toMatchObject({
      problem: wireProblem,
    });
  });

  it("forwards violations[] from a 400 validation problem", async () => {
    const wireProblem = {
      type: "validation-failed",
      title: "Validation failed.",
      status: HttpStatus.BAD_REQUEST,
      instance: "01H-instance",
      "correlation-id": "01H-correlation",
      violations: [{ field: "name", message: "The name field is required." }],
    };
    fetchSpy.mockResolvedValueOnce(
      makeResponse(HttpStatus.BAD_REQUEST, wireProblem, {
        contentType: "application/problem+json",
      }),
    );

    const client = new FetchHttpClient();

    try {
      await client.post("/api/v1/backoffice/banks", { name: "" });
      throw new Error("expected HttpError");
    } catch (err) {
      expect(err).toBeInstanceOf(HttpError);
      const httpError = err as HttpError;
      expect(httpError.problem.status).toBe(HttpStatus.BAD_REQUEST);
      expect(httpError.problem.violations).toEqual([
        { field: "name", message: "The name field is required." },
      ]);
      expect(isProblemDetails(httpError.problem)).toBe(true);
    }
  });

  it("synthesizes a generic ProblemDetails when the body is not RFC 9457", async () => {
    fetchSpy.mockResolvedValueOnce(
      makeResponse(
        HttpStatus.INTERNAL_SERVER_ERROR,
        { message: "boom" },
        {
          headers: { "X-Correlation-Id": "01H-correlation-from-header" },
        },
      ),
    );

    const client = new FetchHttpClient();

    try {
      await client.get("/api/v1/backoffice/banks");
      throw new Error("expected HttpError");
    } catch (err) {
      const httpError = err as HttpError;
      expect(httpError.problem.status).toBe(HttpStatus.INTERNAL_SERVER_ERROR);
      expect(httpError.problem.instance).toBe(STUB_UUID);
      expect(httpError.problem["correlation-id"]).toBe("01H-correlation-from-header");
      expect(isProblemDetails(httpError.problem)).toBe(true);
    }
  });

  it("falls back to a synthetic correlation-id when the header is absent", async () => {
    fetchSpy.mockResolvedValueOnce(makeResponse(HttpStatus.BAD_GATEWAY, null));

    const client = new FetchHttpClient();

    try {
      await client.delete("/api/v1/backoffice/banks/anything");
      throw new Error("expected HttpError");
    } catch (err) {
      const httpError = err as HttpError;
      expect(httpError.problem["correlation-id"]).toBe(STUB_UUID);
      expect(httpError.problem.status).toBe(HttpStatus.BAD_GATEWAY);
    }
  });

  describe("response envelope validation (ResponseGuard)", () => {
    interface Envelope {
      data: string[];
    }

    const isEnvelope = (body: unknown): body is Envelope =>
      typeof body === "object" && body !== null && Array.isArray((body as Envelope).data);

    it("returns the body when the guard accepts it", async () => {
      fetchSpy.mockResolvedValueOnce(makeResponse(HttpStatus.OK, { data: ["a"] }));

      const client = new FetchHttpClient();
      const body = await client.get("/api/v1/backoffice/banks", isEnvelope);

      expect(body).toEqual({ data: ["a"] });
    });

    it("throws a typed malformed-envelope HttpError when a 2xx body fails the guard", async () => {
      fetchSpy.mockResolvedValueOnce(
        makeResponse(
          HttpStatus.OK,
          { data: null },
          { headers: { "X-Correlation-Id": "01H-correlation-from-header" } },
        ),
      );

      const client = new FetchHttpClient();

      try {
        await client.get("/api/v1/backoffice/banks", isEnvelope);
        throw new Error("expected HttpError");
      } catch (err) {
        expect(err).toBeInstanceOf(HttpError);
        const httpError = err as HttpError;
        expect(httpError.problem.type).toBe(MALFORMED_RESPONSE_ENVELOPE);
        expect(httpError.problem.status).toBe(HttpStatus.OK);
        expect(httpError.problem.detail).toContain("/api/v1/backoffice/banks");
        expect(httpError.problem.instance).toBe(STUB_UUID);
        expect(httpError.problem["correlation-id"]).toBe("01H-correlation-from-header");
        expect(isProblemDetails(httpError.problem)).toBe(true);
      }
    });

    it("treats a non-JSON 2xx body as a malformed envelope when a guard is provided", async () => {
      fetchSpy.mockResolvedValueOnce(
        new Response("<html>maintenance</html>", {
          status: HttpStatus.OK,
          headers: { "Content-Type": "text/html" },
        }),
      );

      const client = new FetchHttpClient();

      await expect(client.get("/api/v1/backoffice/banks", isEnvelope)).rejects.toMatchObject({
        problem: { type: MALFORMED_RESPONSE_ENVELOPE },
      });
    });

    it("guards POST and PUT bodies through the same seam", async () => {
      fetchSpy.mockResolvedValueOnce(makeResponse(HttpStatus.CREATED, { wrong: true }));

      const client = new FetchHttpClient();

      await expect(
        client.post("/api/v1/backoffice/banks", { name: "Acme" }, isEnvelope),
      ).rejects.toMatchObject({
        problem: { type: MALFORMED_RESPONSE_ENVELOPE, status: HttpStatus.CREATED },
      });
    });

    it("rejects a guarded 204 as a malformed envelope (a guard means a body is expected)", async () => {
      fetchSpy.mockResolvedValueOnce(makeResponse(HttpStatus.NO_CONTENT, undefined));

      const client = new FetchHttpClient();

      await expect(client.get("/api/v1/backoffice/banks", isEnvelope)).rejects.toMatchObject({
        problem: { type: MALFORMED_RESPONSE_ENVELOPE, status: HttpStatus.NO_CONTENT },
      });
    });

    it("keeps the blind passthrough when no guard is supplied", async () => {
      fetchSpy.mockResolvedValueOnce(makeResponse(HttpStatus.OK, { anything: "goes" }));

      const client = new FetchHttpClient();
      const body = await client.get<{ anything: string }>("/api/v1/backoffice/banks");

      expect(body).toEqual({ anything: "goes" });
    });
  });

  describe("browser base URL (same-origin by default)", () => {
    const ORIGINAL_API_BASE = process.env.NEXT_PUBLIC_API_BASE_URL;

    afterEach(() => {
      if (ORIGINAL_API_BASE === undefined) {
        delete process.env.NEXT_PUBLIC_API_BASE_URL;
      } else {
        process.env.NEXT_PUBLIC_API_BASE_URL = ORIGINAL_API_BASE;
      }
    });

    it("issues a relative request when NEXT_PUBLIC_API_BASE_URL is unset", async () => {
      delete process.env.NEXT_PUBLIC_API_BASE_URL;
      fetchSpy.mockResolvedValueOnce(makeResponse(HttpStatus.OK, { data: [] }));

      const client = new FetchHttpClient();
      await client.get("/api/v1/backoffice/banks");

      const [input] = fetchSpy.mock.calls[0] as [RequestInfo | URL];
      expect(urlOf(input)).toBe("/api/v1/backoffice/banks");
    });

    it("uses the absolute base when NEXT_PUBLIC_API_BASE_URL is set", async () => {
      process.env.NEXT_PUBLIC_API_BASE_URL = "https://api.example.com";
      fetchSpy.mockResolvedValueOnce(makeResponse(HttpStatus.OK, { data: [] }));

      const client = new FetchHttpClient();
      await client.get("/api/v1/backoffice/banks");

      const [input] = fetchSpy.mock.calls[0] as [RequestInfo | URL];
      expect(urlOf(input)).toBe("https://api.example.com/api/v1/backoffice/banks");
    });
  });

  describe("server base URL (absolute by construction)", () => {
    const ORIGINAL_API_BASE = process.env.NEXT_PUBLIC_API_BASE_URL;
    const ORIGINAL_INTERNAL = process.env.SYMFONY_INTERNAL_URL;

    beforeEach(() => {
      // SSR path: the constructor branches on `globalThis.window === undefined`.
      vi.stubGlobal("window", undefined);
    });

    afterEach(() => {
      vi.unstubAllGlobals();
      if (ORIGINAL_API_BASE === undefined) {
        delete process.env.NEXT_PUBLIC_API_BASE_URL;
      } else {
        process.env.NEXT_PUBLIC_API_BASE_URL = ORIGINAL_API_BASE;
      }
      if (ORIGINAL_INTERNAL === undefined) {
        delete process.env.SYMFONY_INTERNAL_URL;
      } else {
        process.env.SYMFONY_INTERNAL_URL = ORIGINAL_INTERNAL;
      }
    });

    it("prefers SYMFONY_INTERNAL_URL and trims its trailing slash", async () => {
      process.env.SYMFONY_INTERNAL_URL = "http://php:80/";
      process.env.NEXT_PUBLIC_API_BASE_URL = "https://api.example.com";
      fetchSpy.mockResolvedValueOnce(makeResponse(HttpStatus.OK, { data: [] }));

      const client = new FetchHttpClient();
      await client.get("/api/v1/backoffice/banks");

      const [input] = fetchSpy.mock.calls[0] as [RequestInfo | URL];
      expect(urlOf(input)).toBe("http://php:80/api/v1/backoffice/banks");
    });

    it("falls back to the public override when SYMFONY_INTERNAL_URL is unset", async () => {
      delete process.env.SYMFONY_INTERNAL_URL;
      process.env.NEXT_PUBLIC_API_BASE_URL = "https://api.example.com";
      fetchSpy.mockResolvedValueOnce(makeResponse(HttpStatus.OK, { data: [] }));

      const client = new FetchHttpClient();
      await client.get("/api/v1/backoffice/banks");

      const [input] = fetchSpy.mock.calls[0] as [RequestInfo | URL];
      expect(urlOf(input)).toBe("https://api.example.com/api/v1/backoffice/banks");
    });

    it("trims a padded SYMFONY_INTERNAL_URL before issuing the request", async () => {
      process.env.SYMFONY_INTERNAL_URL = " http://php:80 ";
      delete process.env.NEXT_PUBLIC_API_BASE_URL;
      fetchSpy.mockResolvedValueOnce(makeResponse(HttpStatus.OK, { data: [] }));

      const client = new FetchHttpClient();
      await client.get("/api/v1/backoffice/banks");

      const [input] = fetchSpy.mock.calls[0] as [RequestInfo | URL];
      expect(urlOf(input)).toBe("http://php:80/api/v1/backoffice/banks");
    });

    it("throws a descriptive error naming both env vars when neither is set", async () => {
      delete process.env.SYMFONY_INTERNAL_URL;
      delete process.env.NEXT_PUBLIC_API_BASE_URL;

      const client = new FetchHttpClient();

      await expect(client.get("/api/v1/backoffice/banks")).rejects.toThrow(
        /SYMFONY_INTERNAL_URL.*NEXT_PUBLIC_API_BASE_URL/s,
      );
      expect(fetchSpy).not.toHaveBeenCalled();
    });

    it("does not throw at construction when neither env var is set (lazy resolution)", () => {
      delete process.env.SYMFONY_INTERNAL_URL;
      delete process.env.NEXT_PUBLIC_API_BASE_URL;

      // The DI container builds this singleton at module-init, where there is no
      // request scope — construction must stay side-effect-free.
      expect(() => new FetchHttpClient()).not.toThrow();
    });
  });
});
