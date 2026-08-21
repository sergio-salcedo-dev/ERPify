import { afterEach, beforeEach, describe, expect, it, vi, type MockInstance } from "vitest";
import { FetchHttpClient } from "@/context/shared/http-client/infrastructure/FetchHttpClient";
import {
  MALFORMED_RESPONSE_ENVELOPE,
  NETWORK_ERROR,
  REQUEST_TIMEOUT,
} from "@/context/shared/http-client/domain/HttpClient";
import { HttpError } from "@/context/shared/http-client/domain/HttpError";
import type { Telemetry } from "@/context/shared/observability/domain/Telemetry";
import { isProblemDetails } from "@/context/shared/error/domain/ProblemDetails";
import { HttpStatus } from "@/context/shared/http-client/domain/HttpStatus";
import { Routes } from "@/context/shared/routing/domain/Routes";
import { NAVIGATION_COMMIT_BUDGET_MS } from "@/context/shared/navigation/infrastructure/hardNavigate";

// v7-shaped: ProblemDetails.instance / correlation-id are UUID v7, minted via @/context/shared/uuid/infrastructure/uuidV7.
const { STUB_UUID } = vi.hoisted(() => ({
  STUB_UUID: "00000000-0000-7000-8000-000000000000",
}));

vi.mock("@/context/shared/uuid/infrastructure/uuidV7", () => ({
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

  it("throws a transport HttpError and reports telemetry when fetch rejects", async () => {
    const cause = new TypeError("Failed to fetch");
    fetchSpy.mockRejectedValueOnce(cause);
    const telemetryErrors: Array<{ message: string; scope?: string; cause?: unknown }> = [];
    const fakeTelemetry: Telemetry = {
      warn: () => {},
      error: (message, context) => {
        telemetryErrors.push({ message, scope: context?.scope, cause: context?.cause });
      },
    };

    const client = new FetchHttpClient(undefined, fakeTelemetry);

    try {
      await client.post("/api/v1/backoffice/banks", { name: "Acme" });
      throw new Error("expected HttpError");
    } catch (err) {
      expect(err).toBeInstanceOf(HttpError);
      const httpError = err as HttpError;
      expect(httpError.problem.type).toBe(NETWORK_ERROR);
      expect(httpError.problem.status).toBe(0);
      expect(httpError.problem.instance).toBe(STUB_UUID);
      expect(httpError.problem["correlation-id"]).toBe(STUB_UUID);
      expect(isProblemDetails(httpError.problem)).toBe(true);
    }

    expect(telemetryErrors).toHaveLength(1);
    expect(telemetryErrors[0].scope).toBe("api:transport");
    expect(telemetryErrors[0].cause).toBe(cause);
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

    it("distinguishes a guarded unparseable body from a shape mismatch in the detail", async () => {
      fetchSpy
        .mockResolvedValueOnce(
          new Response("{not json", {
            status: HttpStatus.OK,
            headers: { "Content-Type": "application/json" },
          }),
        )
        .mockResolvedValueOnce(makeResponse(HttpStatus.OK, { wrong: "shape" }));

      const client = new FetchHttpClient();

      await expect(client.get("/api/v1/backoffice/banks", isEnvelope)).rejects.toMatchObject({
        problem: {
          type: MALFORMED_RESPONSE_ENVELOPE,
          detail: expect.stringContaining("Unparseable JSON response body"),
        },
      });
      await expect(client.get("/api/v1/backoffice/banks", isEnvelope)).rejects.toMatchObject({
        problem: {
          type: MALFORMED_RESPONSE_ENVELOPE,
          detail: expect.stringContaining("Unexpected response body shape"),
        },
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

    it("returns undefined for a guard-less empty 200 body (not only 204)", async () => {
      fetchSpy.mockResolvedValueOnce(makeResponse(HttpStatus.OK, undefined));

      const client = new FetchHttpClient();
      const body = await client.get<void>("/api/v1/backoffice/ping");

      expect(body).toBeUndefined();
    });

    it("returns undefined for a guard-less 204", async () => {
      fetchSpy.mockResolvedValueOnce(makeResponse(HttpStatus.NO_CONTENT, undefined));

      const client = new FetchHttpClient();
      const body = await client.get<void>("/api/v1/backoffice/ping");

      expect(body).toBeUndefined();
    });

    it("mints a synthetic correlation-id for a malformed envelope when the header is absent", async () => {
      fetchSpy.mockResolvedValueOnce(makeResponse(HttpStatus.OK, { data: null }));

      const client = new FetchHttpClient();

      await expect(client.get("/api/v1/backoffice/banks", isEnvelope)).rejects.toMatchObject({
        problem: { type: MALFORMED_RESPONSE_ENVELOPE, "correlation-id": STUB_UUID },
      });
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

  describe("debug token publishing", () => {
    it("publishes the token and profiler link on a 2xx response", async () => {
      const publish = vi.fn();
      const observer = { publish, subscribe: () => () => {} };
      fetchSpy.mockResolvedValue(
        makeResponse(
          200,
          { data: { ok: true } },
          {
            headers: {
              "X-Debug-Token": "tok-2xx",
              "X-Debug-Token-Link": "https://localhost/_profiler/tok-2xx",
            },
          },
        ),
      );

      const client = new FetchHttpClient(observer);
      await client.get("/api/backoffice/health");

      expect(publish).toHaveBeenCalledWith({
        token: "tok-2xx",
        profilerUrl: "https://localhost/_profiler/tok-2xx",
      });
    });

    it("publishes the token on an error response too", async () => {
      const publish = vi.fn();
      const observer = { publish, subscribe: () => () => {} };
      fetchSpy.mockResolvedValue(
        makeResponse(
          404,
          { type: "bank-not-found", title: "x", status: 404 },
          {
            headers: { "X-Debug-Token": "tok-404" },
          },
        ),
      );

      const client = new FetchHttpClient(observer);
      await expect(client.get("/api/backoffice/banks/missing")).rejects.toBeInstanceOf(HttpError);

      expect(publish).toHaveBeenCalledWith({ token: "tok-404", profilerUrl: null });
    });

    it("does not publish when the response carries no X-Debug-Token", async () => {
      const publish = vi.fn();
      const observer = { publish, subscribe: () => () => {} };
      fetchSpy.mockResolvedValue(makeResponse(200, { data: { ok: true } }));

      const client = new FetchHttpClient(observer);
      await client.get("/api/backoffice/health");

      expect(publish).not.toHaveBeenCalled();
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

  describe("the request budget", () => {
    /** Mirrors DEFAULT_TIMEOUT_MS: what every caller gets without asking. */
    const DEFAULT_TIMEOUT_MS = 30_000;

    /** A server that accepted the request and will never answer it. */
    function neverAnswers(): void {
      fetchSpy.mockImplementation(
        (_input: RequestInfo | URL, init?: RequestInit) =>
          new Promise<Response>((_resolve, reject) => {
            init?.signal?.addEventListener("abort", () => {
              reject(new DOMException("The operation was aborted.", "AbortError"));
            });
          }),
      );
    }

    beforeEach(() => {
      vi.useFakeTimers();
    });

    afterEach(() => {
      vi.useRealTimers();
    });

    it("gives up on a request that never answers", async () => {
      neverAnswers();
      const pending = new FetchHttpClient().get("/api/v1/backoffice/banks");
      // Attached before the clock moves: the rejection lands inside advanceTimersByTimeAsync,
      // and an assertion attached after it would be an unhandled rejection first.
      const settled = expect(pending).rejects.toMatchObject({
        problem: { type: REQUEST_TIMEOUT, status: 0 },
      });

      await vi.advanceTimersByTimeAsync(DEFAULT_TIMEOUT_MS);

      await settled;
    });

    it("does not give up before the budget is spent", async () => {
      neverAnswers();
      const pending = new FetchHttpClient().get("/api/v1/backoffice/banks");
      const outcome = vi.fn();
      void pending.then(outcome, outcome);

      await vi.advanceTimersByTimeAsync(DEFAULT_TIMEOUT_MS - 1);

      expect(outcome).not.toHaveBeenCalled();
      // Drain it, so the pending rejection does not surface inside a later test.
      const settled = expect(pending).rejects.toBeInstanceOf(HttpError);
      await vi.advanceTimersByTimeAsync(1);
      await settled;
    });

    it("honours a caller's own budget", async () => {
      neverAnswers();
      // The per-call override is what lets sign-out stop owning a timer of its own: it can
      // state a tighter bound than the default without every other caller inheriting it.
      const pending = new FetchHttpClient().get("/api/v1/backoffice/banks", undefined, {
        timeoutMs: 50,
      });
      const settled = expect(pending).rejects.toMatchObject({
        problem: { type: REQUEST_TIMEOUT },
      });

      await vi.advanceTimersByTimeAsync(50);

      await settled;
    });

    it("tells a timeout apart from never reaching the server", async () => {
      // Both surface as status 0 with no response to correlate against, and reading them as
      // the same thing misleads a triage: an offline client sent nothing, while a timed-out
      // request may well have been received and applied.
      fetchSpy.mockRejectedValue(new TypeError("Failed to fetch"));

      await expect(new FetchHttpClient().get("/api/v1/backoffice/banks")).rejects.toMatchObject({
        problem: { type: NETWORK_ERROR },
      });
    });

    it("gives up on a response whose headers land and whose body never does", async () => {
      // The direction a headers-only bound cannot see, and the one that reproduces the very
      // wedge the sign-out budget exists to prevent: `fetch()` resolves on HEADERS, so a
      // timer cleared at that point leaves the body read unbounded. A chunked reply held
      // open by a proxy then keeps the promise pending for ever, with the controller already
      // unreferenced — the caller never settles, so nothing downstream ever runs.
      fetchSpy.mockImplementation((_input: RequestInfo | URL, init?: RequestInit) =>
        Promise.resolve(
          new Response(
            new ReadableStream({
              start(controller) {
                init?.signal?.addEventListener("abort", () =>
                  controller.error(new Error("aborted")),
                );
              },
            }),
            { status: HttpStatus.OK, headers: new Headers({ "Content-Type": "application/json" }) },
          ),
        ),
      );
      const pending = new FetchHttpClient().get("/api/v1/backoffice/banks");
      const settled = expect(pending).rejects.toMatchObject({
        problem: { type: REQUEST_TIMEOUT, status: 0 },
      });

      await vi.advanceTimersByTimeAsync(DEFAULT_TIMEOUT_MS);

      await settled;
    });

    it("stops the clock once the body lands, not merely the headers", async () => {
      fetchSpy.mockResolvedValue(makeResponse(HttpStatus.OK, { data: [] }));

      await new FetchHttpClient().get("/api/v1/backoffice/banks");
      await vi.advanceTimersByTimeAsync(DEFAULT_TIMEOUT_MS * 2);

      // Nothing is left pending to abort a connection this client is no longer using — and
      // a timer per in-flight request that outlives it is a leak on a long-lived document.
      // Asserted AFTER the whole response is consumed: asserting it the instant headers
      // arrived is what pinned the defect above in place.
      expect(vi.getTimerCount()).toBe(0);
    });
  });

  describe("401 session-expired redirect (browser)", () => {
    const PROBLEM_401 = {
      type: "session-expired",
      title: "Session expired.",
      status: HttpStatus.UNAUTHORIZED,
      instance: "01H-instance",
      "correlation-id": "01H-correlation",
    };
    const ORIGINAL_INTERNAL = process.env.SYMFONY_INTERNAL_URL;

    let replace: MockInstance;
    let assign: MockInstance;

    // The single-flight redirect guard is module-level, so each test gets a fresh
    // module (guard reset) via a dynamic re-import after resetModules.
    async function freshClient(): Promise<InstanceType<typeof FetchHttpClient>> {
      const mod = await import("@/context/shared/http-client/infrastructure/FetchHttpClient");
      return new mod.FetchHttpClient();
    }

    // A fresh Response per call, never one instance reused: a body can be read once, and
    // these cases deliberately issue two or three requests. Handing back the same instance
    // made the second read fail — which the old error path swallowed into a generic 401, so
    // the fixture looked fine while proving less than it claimed.
    function respond401(): void {
      fetchSpy.mockImplementation(() =>
        Promise.resolve(
          makeResponse(HttpStatus.UNAUTHORIZED, PROBLEM_401, {
            contentType: "application/problem+json",
          }),
        ),
      );
    }

    beforeEach(() => {
      vi.resetModules();
      replace = vi.fn();
      // `assign` is stubbed too, and asserted unused: without it, reverting the source to
      // assign() would go red because the stub lacks the method, not because the assertion
      // says replace. The test must fail for the reason it states.
      assign = vi.fn();
      vi.stubGlobal("location", {
        pathname: "/backoffice/banks",
        search: "?page=2",
        replace,
        assign,
      });
    });

    afterEach(() => {
      vi.unstubAllGlobals();
      if (ORIGINAL_INTERNAL === undefined) delete process.env.SYMFONY_INTERNAL_URL;
      else process.env.SYMFONY_INTERNAL_URL = ORIGINAL_INTERNAL;
    });

    it("redirects to /login once on a 401, preserving the blocked target in ?next=", async () => {
      respond401();
      const client = await freshClient();

      // `toMatchObject` (not `toBeInstanceOf`): after resetModules the fresh
      // module throws its own HttpError class, distinct from the static import.
      await expect(client.get("/api/v1/backoffice/banks")).rejects.toMatchObject({
        problem: { status: HttpStatus.UNAUTHORIZED },
      });

      expect(replace).toHaveBeenCalledTimes(1);
      expect(replace).toHaveBeenCalledWith(
        `/login?next=${encodeURIComponent("/backoffice/banks?page=2")}&reason=session-expired`,
      );
      // replace, not assign: the expired page must not stay one Back press away.
      expect(assign).not.toHaveBeenCalled();
    });

    it("redirects only once for concurrent 401s (single-flight)", async () => {
      respond401();
      const client = await freshClient();

      await Promise.allSettled([
        client.get("/api/v1/backoffice/banks"),
        client.get("/api/v1/backoffice/bank-accounts"),
        client.get("/api/v1/backoffice/audit/timeline"),
      ]);

      expect(replace).toHaveBeenCalledTimes(1);
    });

    it("does not redirect on a 200", async () => {
      fetchSpy.mockResolvedValue(makeResponse(HttpStatus.OK, { data: [] }));
      const client = await freshClient();

      await client.get("/api/v1/backoffice/banks");

      expect(replace).not.toHaveBeenCalled();
    });

    it("does not redirect for the /me cold-load probe (AuthProvider owns that 401)", async () => {
      respond401();
      const client = await freshClient();

      await expect(client.get("/api/v1/me")).rejects.toMatchObject({
        problem: { status: HttpStatus.UNAUTHORIZED },
      });

      expect(replace).not.toHaveBeenCalled();
    });

    it("does not redirect for the sign-out call's own 401 (already-expired session)", async () => {
      respond401();
      const client = await freshClient();

      await expect(client.post("/api/v1/sessions/revoke-current", {})).rejects.toMatchObject({
        problem: { status: HttpStatus.UNAUTHORIZED },
      });

      // Bouncing here would race the sign-out's own navigation to the public landing and
      // strand the user on "session expired" instead.
      expect(replace).not.toHaveBeenCalled();
    });

    it("does not redirect when the document is already on /login", async () => {
      vi.stubGlobal("location", { pathname: Routes.LOGIN, search: "", replace });
      respond401();
      const client = await freshClient();

      await expect(client.get("/api/v1/backoffice/banks")).rejects.toMatchObject({
        problem: { status: HttpStatus.UNAUTHORIZED },
      });

      // The latch is module state a fresh document resets, so replacing /login with itself
      // could reload the screen for as long as the call keeps failing.
      expect(replace).not.toHaveBeenCalled();
    });

    it("clears the single-flight latch when the navigation throws", async () => {
      // No known browser raises here — replace() performs no security check and a sandboxed
      // navigable ignores the navigation silently. This pins the contract for an environment
      // that cannot navigate at all: the exception must not escape as a raw throw, and the
      // latch must not wedge the bounce for the rest of the document's life.
      replace.mockImplementationOnce(() => {
        throw new Error("cannot navigate");
      });
      respond401();
      const client = await freshClient();

      // The DOMException must not escape the adapter's HttpError contract.
      await expect(client.get("/api/v1/backoffice/banks")).rejects.toMatchObject({
        problem: { status: HttpStatus.UNAUTHORIZED },
      });
      await expect(client.get("/api/v1/backoffice/banks")).rejects.toMatchObject({
        problem: { status: HttpStatus.UNAUTHORIZED },
      });

      // The page never left, so the second 401 must still be able to bounce.
      expect(replace).toHaveBeenCalledTimes(2);
    });

    it("clears the single-flight latch when the navigation is ignored rather than refused", async () => {
      // The sibling of the case above, and the one that actually happens: a sandboxed navigable
      // DROPS the navigation without raising, so the `catch` never runs. The latch survived, and
      // every later 401 in that document was swallowed — no bounce, no signal, for the life of
      // the page. Nothing here can observe an ignored navigation directly; what it observes is
      // the document still being alive when the commit budget runs out.
      vi.useFakeTimers({ shouldAdvanceTime: true });
      try {
        respond401();
        const client = await freshClient();

        await expect(client.get("/api/v1/backoffice/banks")).rejects.toMatchObject({
          problem: { status: HttpStatus.UNAUTHORIZED },
        });
        expect(replace).toHaveBeenCalledTimes(1);

        await vi.advanceTimersByTimeAsync(NAVIGATION_COMMIT_BUDGET_MS);

        await expect(client.get("/api/v1/backoffice/banks")).rejects.toMatchObject({
          problem: { status: HttpStatus.UNAUTHORIZED },
        });
        expect(replace).toHaveBeenCalledTimes(2);
      } finally {
        vi.useRealTimers();
      }
    });

    it("does not redirect for the login endpoint's own 401 (bad credentials)", async () => {
      respond401();
      const client = await freshClient();

      await expect(
        client.post("/api/v1/backoffice/login", { email: "a@b.com", password: "x" }),
      ).rejects.toMatchObject({ problem: { status: HttpStatus.UNAUTHORIZED } });

      expect(replace).not.toHaveBeenCalled();
    });

    it("does not redirect during SSR (no window)", async () => {
      vi.stubGlobal("window", undefined);
      process.env.SYMFONY_INTERNAL_URL = "http://php:80";
      respond401();
      const client = await freshClient();

      await expect(client.get("/api/v1/backoffice/banks")).rejects.toMatchObject({
        problem: { status: HttpStatus.UNAUTHORIZED },
      });

      expect(replace).not.toHaveBeenCalled();
    });

    // The exemption list covers the sign-out CALL. It never covered everything else in flight
    // beside it — a dashboard poll, a Mercure authorize, a list refetch — any of which 401s in
    // the same window and used to fire a SECOND `location.replace`, to `?reason=session-expired`.
    // The later navigation generally wins, so a user who asked to sign out landed on "session
    // expired": the outcome the exemption exists to prevent, reached around it.
    it("does not bounce a concurrent 401 while a sign-out is already leaving", async () => {
      respond401();
      // Imported after `resetModules`, so this is the same module instance the fresh client
      // writes to. A static import here would claim a different one and prove nothing.
      const { claimDeparture, DepartureReason } =
        await import("@/context/shared/navigation/application/departure");
      claimDeparture(DepartureReason.SIGN_OUT);
      const client = await freshClient();

      await expect(client.get("/api/v1/backoffice/banks")).rejects.toMatchObject({
        problem: { status: HttpStatus.UNAUTHORIZED },
      });

      // The 401 still travels back to its caller unchanged; what it no longer does is navigate.
      expect(replace).not.toHaveBeenCalled();
      expect(assign).not.toHaveBeenCalled();
    });

    // Releasing the claim on a navigation that never committed is what #786 required, and the
    // cost is that the NEXT 401 re-claims and re-blanks the tree for another commit budget.
    // With `useMercureRealtime` re-firing `authorize()` on every reconnect, an environment that
    // drops navigations cycles the app blank/usable for ever — unmounting page state and unsaved
    // input each time. Two attempts, then the 401 renders as the ordinary error its caller
    // already handles.
    it("stops re-blanking the application after two navigations that never committed", async () => {
      vi.useFakeTimers({ shouldAdvanceTime: true });
      try {
        respond401();
        const client = await freshClient();

        for (const expected of [1, 2]) {
          await expect(client.get("/api/v1/backoffice/banks")).rejects.toMatchObject({
            problem: { status: HttpStatus.UNAUTHORIZED },
          });
          expect(replace).toHaveBeenCalledTimes(expected);
          await vi.advanceTimersByTimeAsync(NAVIGATION_COMMIT_BUDGET_MS);
        }

        await expect(client.get("/api/v1/backoffice/banks")).rejects.toMatchObject({
          problem: { status: HttpStatus.UNAUTHORIZED },
        });

        expect(replace).toHaveBeenCalledTimes(2);
      } finally {
        vi.useRealTimers();
      }
    });

    // The cap counts give-ups, never bounces. A navigation that COMMITS discards the document and
    // this counter with it, so a budget spent by a real navigation is not a budget at all — it
    // would silently halve what the cap allows on the one path that is working correctly.
    // Three rounds, because two would still pass under a counter that increments per bounce.
    it("does not spend the budget on a bounce whose document actually left", async () => {
      vi.useFakeTimers({ shouldAdvanceTime: true });
      try {
        respond401();
        const { releaseDeparture } =
          await import("@/context/shared/navigation/application/departure");
        const client = await freshClient();

        for (const expected of [1, 2, 3]) {
          await expect(client.get("/api/v1/backoffice/banks")).rejects.toMatchObject({
            problem: { status: HttpStatus.UNAUTHORIZED },
          });
          expect(replace).toHaveBeenCalledTimes(expected);

          // `pagehide` with `persisted: false` IS the commit: the document is being discarded, so
          // the budget timer disarms and nothing is counted. Standing in for a real document
          // teardown, which a test cannot perform — hence the explicit release afterwards.
          globalThis.dispatchEvent(
            Object.assign(new Event("pagehide"), { persisted: false }) as PageTransitionEvent,
          );
          await vi.advanceTimersByTimeAsync(NAVIGATION_COMMIT_BUDGET_MS);
          releaseDeparture();
        }
      } finally {
        vi.useRealTimers();
      }
    });
  });
});
