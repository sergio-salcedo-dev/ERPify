# Surface the Symfony Debug Toolbar inside the Next.js PWA — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Show Symfony's floating Web Debug Toolbar while using the real Next.js PWA (not only the Symfony-served `/_dev` page), dev/test only, excluded from production.

**Architecture:** Every `/api/*` response is read in one place — `FetchHttpClient`. It publishes the per-request profiler token (`X-Debug-Token` + `X-Debug-Token-Link`) through a `DebugTokenObserver` domain port. A dev-only React component subscribes via a hook, fetches Symfony's `/_wdt/{token}` fragment (same-origin, already routed through FrankenPHP), and mounts it fixed to the bottom of the viewport. Production binds a no-op observer and never mounts the component.

**Tech Stack:** Next.js 16 (App Router) · TypeScript (strict) · Inversify · Vitest · Symfony 8 Web Profiler (already installed, PR #260).

**Spec:** `docs/superpowers/specs/2026-06-14-pwa-symfony-debug-toolbar-design.md`

**Working directory:** worktree `.claude/worktrees/shared-pwa-debug-toolbar-10pm`, branch `feat/shared-pwa-debug-toolbar-10pm`. Run all `make` targets from the repo root of this worktree.

---

## File structure

**Created (PWA):**
- `pwa/src/context/shared/domain/DebugToken/DebugToken.ts` — pure type `DebugToken`.
- `pwa/src/context/shared/domain/DebugToken/DebugTokenObserver.ts` — the port interface.
- `pwa/src/context/shared/infrastructure/DebugToken/NoopDebugTokenObserver.ts` — inert prod adapter.
- `pwa/src/context/shared/infrastructure/DebugToken/EventTargetDebugTokenObserver.ts` — dev adapter (pub/sub + latest replay).
- `pwa/src/context/shared/dev-tools/infrastructure/ui/useLatestDebugToken.ts` — React hook over the observer.
- `pwa/src/context/shared/dev-tools/infrastructure/ui/SymfonyDebugToolbar.tsx` — dev-only toolbar component.
- Tests mirroring each under `pwa/tests/...`.

**Modified (PWA):**
- `pwa/src/context/shared/infrastructure/HttpClient/HttpClient.ts` — `FetchHttpClient` publishes the token.
- `pwa/src/context/shared/infrastructure/DependencyInjection/Container.ts` — bind `DebugTokenObserver`.
- `pwa/src/app/layout.tsx` — mount `<SymfonyDebugToolbar />` behind `isDevToolsAvailable()`.

**API:** expected **zero** code change. Task 1 (spike) decides whether the contingent Task 9b (a dev-only Symfony loader route) is needed.

**Docs (Task 11):** `pwa/CLAUDE.md` (new shared building block), `docs/architecture-pwa.md`, `docs/claude-code-quickref.md` if a directory is added to the layout tables.

---

## Task 1: Spike — is `/_wdt/{token}` self-contained?

No test; this is a decision that selects the toolbar's injection source. Time-box ~20 min.

**Files:** none (investigation).

- [ ] **Step 1: Boot the worktree stack**

Run from the worktree root:
```bash
make app.dev
```
Expected: stack up. Note the HTTPS port from `make docker.info` (worktree stacks use an ephemeral port; call it `$PORT`).

- [ ] **Step 2: Capture a real profiler token**

```bash
curl -ksD - "https://localhost:$PORT/api/backoffice/health" -o /dev/null | grep -i x-debug-token
```
Expected: an `x-debug-token:` header and an `x-debug-token-link:` header. Copy the token value (call it `$TOKEN`).

- [ ] **Step 3: Fetch the toolbar fragment and inspect it**

```bash
curl -ks "https://localhost:$PORT/_wdt/$TOKEN" | head -c 4000
```
Decision:
- **Approach A (expected):** the fragment is self-contained — it includes its own `<script>` defining the `Sfjs` loader and inline `<style>`. Then injecting this fragment (recreating its `<script>` nodes) renders the toolbar with no API change. **Skip Task 9b.**
- **Approach B (fallback):** the fragment is bare markup that references a loader defined elsewhere (i.e. it does nothing on its own). Then **do Task 9b** (add a dev-only `/_dev/wdt-loader/{token}` Symfony route that renders `@WebProfiler/Profiler/toolbar_js.html.twig`) and point the component's fetch at that route instead of `/_wdt/{token}`.

- [ ] **Step 4: Record the decision**

Add a one-line note to the spec's "Open question" section stating A or B and commit:
```bash
git commit -am "docs(spec): record /_wdt fragment spike outcome (Approach A|B)"
```

---

## Task 2: `DebugToken` domain type

**Files:**
- Create: `pwa/src/context/shared/domain/DebugToken/DebugToken.ts`

This is a pure type — no runtime test. It is exercised by every later task's tests.

- [ ] **Step 1: Write the type**

```typescript
/**
 * The per-request Symfony profiler handle, read from a `/api/*` response.
 * `token` indexes the profile (`/_wdt/{token}`, `/_profiler/{token}`);
 * `profilerUrl` is the absolute profiler link Symfony emits, or `null` when the
 * response carried no `X-Debug-Token-Link` header.
 */
export interface DebugToken {
  readonly token: string;
  readonly profilerUrl: string | null;
}
```

- [ ] **Step 2: Verify it compiles**

Run: `make pwa.quality`
Expected: PASS (no new lint/type error). (A full type-check runs in `make pwa.test.unit`; this step is a cheap sanity check.)

- [ ] **Step 3: Commit**

```bash
git add pwa/src/context/shared/domain/DebugToken/DebugToken.ts
git commit -m "feat(shared): add DebugToken domain type"
```

---

## Task 3: `DebugTokenObserver` port

**Files:**
- Create: `pwa/src/context/shared/domain/DebugToken/DebugTokenObserver.ts`

Pure interface — no runtime test.

- [ ] **Step 1: Write the port**

```typescript
import type { DebugToken } from "./DebugToken";

/**
 * Publish/subscribe seam carrying the latest {@link DebugToken} from the HTTP
 * layer (`FetchHttpClient`) to the dev-only toolbar UI. A domain port — never a
 * `window` global — so the HTTP client stays unit-testable and production can
 * bind an inert adapter. Bound under the Inversify key `"DebugTokenObserver"`.
 */
export interface DebugTokenObserver {
  /** Record the most recent token and notify current subscribers. */
  publish(token: DebugToken): void;
  /**
   * Register a listener. If a token was already published, the listener is
   * invoked immediately with the latest value (late-subscriber replay).
   * Returns an unsubscribe function.
   */
  subscribe(listener: (token: DebugToken) => void): () => void;
}
```

- [ ] **Step 2: Verify it compiles**

Run: `make pwa.quality`
Expected: PASS.

- [ ] **Step 3: Commit**

```bash
git add pwa/src/context/shared/domain/DebugToken/DebugTokenObserver.ts
git commit -m "feat(shared): add DebugTokenObserver port"
```

---

## Task 4: `NoopDebugTokenObserver` (prod adapter)

**Files:**
- Create: `pwa/src/context/shared/infrastructure/DebugToken/NoopDebugTokenObserver.ts`
- Test: `pwa/tests/context/shared/infrastructure/DebugToken/NoopDebugTokenObserver.test.ts`

- [ ] **Step 1: Write the failing test**

```typescript
import { describe, expect, it } from "vitest";
import { NoopDebugTokenObserver } from "@/context/shared/infrastructure/DebugToken/NoopDebugTokenObserver";

describe("NoopDebugTokenObserver", () => {
  it("never notifies a subscriber and publish is inert", () => {
    const observer = new NoopDebugTokenObserver();
    let called = false;
    const unsubscribe = observer.subscribe(() => {
      called = true;
    });

    observer.publish({ token: "abc", profilerUrl: "/_profiler/abc" });

    expect(called).toBe(false);
    expect(typeof unsubscribe).toBe("function");
    expect(() => unsubscribe()).not.toThrow();
  });
});
```

- [ ] **Step 2: Run test to verify it fails**

Run: `make pwa.test.unit c='tests/context/shared/infrastructure/DebugToken/NoopDebugTokenObserver.test.ts'`
Expected: FAIL — cannot resolve `NoopDebugTokenObserver`.

- [ ] **Step 3: Write minimal implementation**

```typescript
import { injectable } from "inversify";
import type { DebugToken } from "@/context/shared/domain/DebugToken/DebugToken";
import type { DebugTokenObserver } from "@/context/shared/domain/DebugToken/DebugTokenObserver";

/**
 * Production adapter: the toolbar does not exist in prod, so both operations are
 * inert. Bound as `"DebugTokenObserver"` in production builds and used as the
 * default for `FetchHttpClient` when no observer is injected (tests).
 */
@injectable()
export class NoopDebugTokenObserver implements DebugTokenObserver {
  publish(_token: DebugToken): void {}

  subscribe(_listener: (token: DebugToken) => void): () => void {
    return () => {};
  }
}
```

- [ ] **Step 4: Run test to verify it passes**

Run: `make pwa.test.unit c='tests/context/shared/infrastructure/DebugToken/NoopDebugTokenObserver.test.ts'`
Expected: PASS.

- [ ] **Step 5: Commit**

```bash
git add pwa/src/context/shared/infrastructure/DebugToken/NoopDebugTokenObserver.ts pwa/tests/context/shared/infrastructure/DebugToken/NoopDebugTokenObserver.test.ts
git commit -m "feat(shared): add inert NoopDebugTokenObserver adapter"
```

---

## Task 5: `EventTargetDebugTokenObserver` (dev adapter)

**Files:**
- Create: `pwa/src/context/shared/infrastructure/DebugToken/EventTargetDebugTokenObserver.ts`
- Test: `pwa/tests/context/shared/infrastructure/DebugToken/EventTargetDebugTokenObserver.test.ts`

- [ ] **Step 1: Write the failing test**

```typescript
import { describe, expect, it, vi } from "vitest";
import { EventTargetDebugTokenObserver } from "@/context/shared/infrastructure/DebugToken/EventTargetDebugTokenObserver";
import type { DebugToken } from "@/context/shared/domain/DebugToken/DebugToken";

const tokenA: DebugToken = { token: "aaa", profilerUrl: "/_profiler/aaa" };
const tokenB: DebugToken = { token: "bbb", profilerUrl: "/_profiler/bbb" };

describe("EventTargetDebugTokenObserver", () => {
  it("delivers a published token to a current subscriber", () => {
    const observer = new EventTargetDebugTokenObserver();
    const listener = vi.fn();
    observer.subscribe(listener);

    observer.publish(tokenA);

    expect(listener).toHaveBeenCalledWith(tokenA);
  });

  it("replays the latest token to a subscriber that attaches after publish", () => {
    const observer = new EventTargetDebugTokenObserver();
    observer.publish(tokenA);
    observer.publish(tokenB);

    const listener = vi.fn();
    observer.subscribe(listener);

    expect(listener).toHaveBeenCalledTimes(1);
    expect(listener).toHaveBeenCalledWith(tokenB);
  });

  it("stops delivering after unsubscribe", () => {
    const observer = new EventTargetDebugTokenObserver();
    const listener = vi.fn();
    const unsubscribe = observer.subscribe(listener);
    unsubscribe();

    observer.publish(tokenA);

    expect(listener).not.toHaveBeenCalled();
  });
});
```

- [ ] **Step 2: Run test to verify it fails**

Run: `make pwa.test.unit c='tests/context/shared/infrastructure/DebugToken/EventTargetDebugTokenObserver.test.ts'`
Expected: FAIL — cannot resolve `EventTargetDebugTokenObserver`.

- [ ] **Step 3: Write minimal implementation**

```typescript
import { injectable } from "inversify";
import type { DebugToken } from "@/context/shared/domain/DebugToken/DebugToken";
import type { DebugTokenObserver } from "@/context/shared/domain/DebugToken/DebugTokenObserver";

const EVENT_NAME = "erpify:debug-token";

/**
 * Dev adapter backed by an {@link EventTarget}. Retains the latest token so a
 * subscriber attaching after the first `/api/*` response (the common case — the
 * toolbar mounts on first paint, the response lands shortly after, or a route
 * change replays) is delivered the current value immediately.
 */
@injectable()
export class EventTargetDebugTokenObserver implements DebugTokenObserver {
  private readonly bus = new EventTarget();
  private latest: DebugToken | null = null;

  publish(token: DebugToken): void {
    this.latest = token;
    this.bus.dispatchEvent(new CustomEvent<DebugToken>(EVENT_NAME, { detail: token }));
  }

  subscribe(listener: (token: DebugToken) => void): () => void {
    const handler = (event: Event) => {
      listener((event as CustomEvent<DebugToken>).detail);
    };
    this.bus.addEventListener(EVENT_NAME, handler);

    if (this.latest !== null) {
      listener(this.latest);
    }

    return () => this.bus.removeEventListener(EVENT_NAME, handler);
  }
}
```

- [ ] **Step 4: Run test to verify it passes**

Run: `make pwa.test.unit c='tests/context/shared/infrastructure/DebugToken/EventTargetDebugTokenObserver.test.ts'`
Expected: PASS (3 tests).

- [ ] **Step 5: Commit**

```bash
git add pwa/src/context/shared/infrastructure/DebugToken/EventTargetDebugTokenObserver.ts pwa/tests/context/shared/infrastructure/DebugToken/EventTargetDebugTokenObserver.test.ts
git commit -m "feat(shared): add EventTargetDebugTokenObserver dev adapter"
```

---

## Task 6: `FetchHttpClient` publishes the token

**Files:**
- Modify: `pwa/src/context/shared/infrastructure/HttpClient/HttpClient.ts`
- Test: `pwa/tests/context/shared/infrastructure/HttpClient/FetchHttpClient.test.ts` (add cases; existing `new FetchHttpClient()` calls stay valid because the new param is optional)

- [ ] **Step 1: Write the failing tests**

Append inside the existing top-level `describe("FetchHttpClient", ...)` block in `FetchHttpClient.test.ts`. The file already defines `makeResponse(status, body, { headers })` and a `fetchSpy`.

```typescript
  describe("debug token publishing", () => {
    it("publishes the token and profiler link on a 2xx response", async () => {
      const publish = vi.fn();
      const observer = { publish, subscribe: () => () => {} };
      fetchSpy.mockResolvedValue(
        makeResponse(200, { data: { ok: true } }, {
          headers: {
            "X-Debug-Token": "tok-2xx",
            "X-Debug-Token-Link": "https://localhost/_profiler/tok-2xx",
          },
        }),
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
        makeResponse(404, { type: "bank-not-found", title: "x", status: 404 }, {
          headers: { "X-Debug-Token": "tok-404" },
        }),
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
```

- [ ] **Step 2: Run tests to verify they fail**

Run: `make pwa.test.unit c='tests/context/shared/infrastructure/HttpClient/FetchHttpClient.test.ts'`
Expected: FAIL — `new FetchHttpClient(observer)` rejects an argument / `publish` never called (constructor takes no param yet).

- [ ] **Step 3: Modify `FetchHttpClient`**

In `HttpClient.ts`, change the import line and add the constructor + a `request` wrapper. Replace the import:

```typescript
import { inject, injectable } from "inversify";
```

Add these imports near the top (after the existing imports):

```typescript
import type { DebugTokenObserver } from "../../domain/DebugToken/DebugTokenObserver";
import { NoopDebugTokenObserver } from "../DebugToken/NoopDebugTokenObserver";
```

Add the constructor and helpers at the top of the `FetchHttpClient` class body:

```typescript
  // Optional + defaulted so the ~20 direct `new FetchHttpClient()` call sites in
  // the unit suite keep working; the container always binds a real observer, so
  // production/dev resolution never hits the default.
  constructor(
    @inject("DebugTokenObserver")
    private readonly debugTokens: DebugTokenObserver = new NoopDebugTokenObserver(),
  ) {}

  // Single fetch chokepoint: every request reads the Symfony profiler token off
  // the response (success and error paths share this) and publishes it for the
  // dev-only toolbar. No-op in prod (header absent + inert observer).
  private async request(input: string, init: RequestInit): Promise<Response> {
    const res = await fetch(input, init);
    const token = res.headers.get("X-Debug-Token");
    if (token) {
      this.debugTokens.publish({ token, profilerUrl: res.headers.get("X-Debug-Token-Link") });
    }
    return res;
  }
```

Then replace each `await fetch(this.resolveUrl(url), { ... })` with `await this.request(this.resolveUrl(url), { ... })`. There are three call sites: in `get()`, in `delete()`, and in `sendWithBody()` (which backs `post()`/`put()`). Example for `get()`:

```typescript
  async get<T>(url: string, validate?: ResponseGuard<T>): Promise<T> {
    const res = await this.request(this.resolveUrl(url), {
      headers: { Accept: "application/json" },
      cache: "no-store",
    });

    if (!res.ok) {
      throw await this.toHttpError(res);
    }

    return this.parseBody<T>(res, url, validate);
  }
```

Apply the identical `fetch` → `this.request` swap in `delete()` and `sendWithBody()`.

- [ ] **Step 4: Run tests to verify they pass**

Run: `make pwa.test.unit c='tests/context/shared/infrastructure/HttpClient/FetchHttpClient.test.ts'`
Expected: PASS — the 3 new cases plus all pre-existing cases (the optional default keeps `new FetchHttpClient()` valid).

- [ ] **Step 5: PHPStan-equivalent type check + lint**

Run: `make pwa.quality`
Expected: PASS.

- [ ] **Step 6: Commit**

```bash
git add pwa/src/context/shared/infrastructure/HttpClient/HttpClient.ts pwa/tests/context/shared/infrastructure/HttpClient/FetchHttpClient.test.ts
git commit -m "feat(shared): publish Symfony debug token from FetchHttpClient"
```

---

## Task 7: Bind `DebugTokenObserver` in the container

**Files:**
- Modify: `pwa/src/context/shared/infrastructure/DependencyInjection/Container.ts`
- Test: `pwa/tests/context/shared/infrastructure/DependencyInjection/DebugTokenObserverBinding.test.ts`

- [ ] **Step 1: Write the failing test**

```typescript
import { describe, expect, it } from "vitest";
import { container } from "@/context/shared/infrastructure/DependencyInjection/Container";
import { EventTargetDebugTokenObserver } from "@/context/shared/infrastructure/DebugToken/EventTargetDebugTokenObserver";
import type { DebugTokenObserver } from "@/context/shared/domain/DebugToken/DebugTokenObserver";

describe("DebugTokenObserver binding", () => {
  it("resolves the live EventTarget adapter outside production (test env)", () => {
    const observer = container.get<DebugTokenObserver>("DebugTokenObserver");
    expect(observer).toBeInstanceOf(EventTargetDebugTokenObserver);
  });

  it("resolves a singleton", () => {
    const a = container.get<DebugTokenObserver>("DebugTokenObserver");
    const b = container.get<DebugTokenObserver>("DebugTokenObserver");
    expect(a).toBe(b);
  });
});
```

- [ ] **Step 2: Run test to verify it fails**

Run: `make pwa.test.unit c='tests/context/shared/infrastructure/DependencyInjection/DebugTokenObserverBinding.test.ts'`
Expected: FAIL — no binding registered for `"DebugTokenObserver"`.

- [ ] **Step 3: Add the binding**

In `Container.ts`, add these imports with the other infrastructure imports:

```typescript
import type { DebugTokenObserver } from "../../domain/DebugToken/DebugTokenObserver";
import { EventTargetDebugTokenObserver } from "../DebugToken/EventTargetDebugTokenObserver";
import { NoopDebugTokenObserver } from "../DebugToken/NoopDebugTokenObserver";
import { isDevToolsAvailable } from "../../dev-tools/domain/isDevToolsAvailable";
```

Insert the binding immediately after `const container = new Container();` and before the `useMockHttp` block (so `FetchHttpClient` can resolve it):

```typescript
// The debug toolbar exists only outside production. The dev adapter carries the
// profiler token to the toolbar; prod binds the inert no-op so the feature is
// dead by construction even before the (also-absent) profiler header.
container
  .bind<DebugTokenObserver>("DebugTokenObserver")
  .to(isDevToolsAvailable() ? EventTargetDebugTokenObserver : NoopDebugTokenObserver)
  .inSingletonScope();
```

- [ ] **Step 4: Run test to verify it passes**

Run: `make pwa.test.unit c='tests/context/shared/infrastructure/DependencyInjection/DebugTokenObserverBinding.test.ts'`
Expected: PASS (2 tests).

- [ ] **Step 5: Commit**

```bash
git add pwa/src/context/shared/infrastructure/DependencyInjection/Container.ts pwa/tests/context/shared/infrastructure/DependencyInjection/DebugTokenObserverBinding.test.ts
git commit -m "feat(shared): bind DebugTokenObserver (dev adapter, prod no-op)"
```

---

## Task 8: `useLatestDebugToken` hook

**Files:**
- Create: `pwa/src/context/shared/dev-tools/infrastructure/ui/useLatestDebugToken.ts`
- Test: `pwa/tests/context/shared/dev-tools/infrastructure/ui/useLatestDebugToken.test.ts`

- [ ] **Step 1: Write the failing test**

```typescript
import { act, renderHook } from "@testing-library/react";
import { describe, expect, it } from "vitest";
import { useLatestDebugToken } from "@/context/shared/dev-tools/infrastructure/ui/useLatestDebugToken";
import { EventTargetDebugTokenObserver } from "@/context/shared/infrastructure/DebugToken/EventTargetDebugTokenObserver";
import type { DebugToken } from "@/context/shared/domain/DebugToken/DebugToken";

const token: DebugToken = { token: "ccc", profilerUrl: "/_profiler/ccc" };

describe("useLatestDebugToken", () => {
  it("returns null before any publish, then the latest token after one", () => {
    const observer = new EventTargetDebugTokenObserver();
    const { result } = renderHook(() => useLatestDebugToken(observer));

    expect(result.current).toBeNull();

    act(() => observer.publish(token));

    expect(result.current).toEqual(token);
  });

  it("unsubscribes on unmount (no throw on later publish)", () => {
    const observer = new EventTargetDebugTokenObserver();
    const { unmount } = renderHook(() => useLatestDebugToken(observer));
    unmount();
    expect(() => observer.publish(token)).not.toThrow();
  });
});
```

- [ ] **Step 2: Run test to verify it fails**

Run: `make pwa.test.unit c='tests/context/shared/dev-tools/infrastructure/ui/useLatestDebugToken.test.ts'`
Expected: FAIL — cannot resolve `useLatestDebugToken`.

- [ ] **Step 3: Write minimal implementation**

The hook takes an optional observer (defaulting to the container binding) so tests inject a fresh instance without touching the singleton.

```typescript
"use client";

import { useEffect, useState } from "react";
import { container } from "@/context/shared/infrastructure/DependencyInjection/Container";
import type { DebugToken } from "@/context/shared/domain/DebugToken/DebugToken";
import type { DebugTokenObserver } from "@/context/shared/domain/DebugToken/DebugTokenObserver";

/**
 * Subscribes to the latest Symfony profiler {@link DebugToken}. Returns `null`
 * until the first `/api/*` response carrying a token. `observer` is injectable
 * for tests; by default it resolves the container's singleton adapter.
 */
export function useLatestDebugToken(
  observer: DebugTokenObserver = container.get<DebugTokenObserver>("DebugTokenObserver"),
): DebugToken | null {
  const [token, setToken] = useState<DebugToken | null>(null);

  useEffect(() => {
    return observer.subscribe(setToken);
  }, [observer]);

  return token;
}
```

- [ ] **Step 4: Run test to verify it passes**

Run: `make pwa.test.unit c='tests/context/shared/dev-tools/infrastructure/ui/useLatestDebugToken.test.ts'`
Expected: PASS (2 tests).

- [ ] **Step 5: Commit**

```bash
git add pwa/src/context/shared/dev-tools/infrastructure/ui/useLatestDebugToken.ts pwa/tests/context/shared/dev-tools/infrastructure/ui/useLatestDebugToken.test.ts
git commit -m "feat(dev-tools): add useLatestDebugToken hook"
```

---

## Task 9: `<SymfonyDebugToolbar>` component (Approach B — spike confirmed bare fragment)

> **Spike outcome (2026-06-14):** `/_wdt/{token}` is bare markup, so **Approach B applies**. Task 9b shipped the dev-only loader endpoint `GET /_dev/wdt-loader/{token}` (commit `7c0c544`), which returns the real `Sfjs` loader (it pulls `/_wdt/{token}` + `/_wdt/styles` itself). The component therefore fetches `/_dev/wdt-loader/{token}`, **not** `/_wdt/{token}`. `WDT_PATH` below is set accordingly; everything else in this task is unchanged.

**Files:**
- Create: `pwa/src/context/shared/dev-tools/infrastructure/ui/SymfonyDebugToolbar.tsx`
- Test: `pwa/tests/context/shared/dev-tools/infrastructure/ui/SymfonyDebugToolbar.test.tsx`

The component fetches `/_wdt/{token}` and mounts the returned fragment into a fixed-bottom container. It uses `DOMParser` + `appendChild` + `<script>` revival — **not** `innerHTML` / `dangerouslySetInnerHTML` — so it stays within the repo's banned-sink policy. The fragment is dev-only, same-origin, trusted Symfony output, never reached in production.

- [ ] **Step 1: Write the failing test**

```typescript
import { render, screen, waitFor } from "@testing-library/react";
import { afterEach, describe, expect, it, vi } from "vitest";
import { SymfonyDebugToolbar } from "@/context/shared/dev-tools/infrastructure/ui/SymfonyDebugToolbar";
import { EventTargetDebugTokenObserver } from "@/context/shared/infrastructure/DebugToken/EventTargetDebugTokenObserver";

afterEach(() => {
  vi.restoreAllMocks();
});

describe("SymfonyDebugToolbar", () => {
  it("renders nothing until a token is published", () => {
    const observer = new EventTargetDebugTokenObserver();
    const { container } = render(<SymfonyDebugToolbar observer={observer} />);
    expect(container.querySelector("[data-testid='dev-tools__symfony-toolbar']")).toBeNull();
  });

  it("fetches /_wdt/{token} and mounts the fragment when a token arrives", async () => {
    const observer = new EventTargetDebugTokenObserver();
    const fetchSpy = vi.spyOn(globalThis, "fetch").mockResolvedValue(
      new Response("<div id='sfwdt-marker'>toolbar</div>", {
        status: 200,
        headers: { "Content-Type": "text/html" },
      }),
    );

    render(<SymfonyDebugToolbar observer={observer} />);
    observer.publish({ token: "ddd", profilerUrl: "/_profiler/ddd" });

    await waitFor(() => {
      expect(fetchSpy).toHaveBeenCalledWith("/_dev/wdt-loader/ddd", expect.objectContaining({ cache: "no-store" }));
    });
    await waitFor(() => {
      expect(screen.getByTestId("dev-tools__symfony-toolbar").querySelector("#sfwdt-marker")).not.toBeNull();
    });
  });

  it("renders nothing and does not throw when the fragment fetch fails", async () => {
    const observer = new EventTargetDebugTokenObserver();
    vi.spyOn(globalThis, "fetch").mockRejectedValue(new Error("network"));

    render(<SymfonyDebugToolbar observer={observer} />);
    observer.publish({ token: "eee", profilerUrl: null });

    await waitFor(() => {
      const host = screen.getByTestId("dev-tools__symfony-toolbar");
      expect(host.childElementCount).toBe(0);
    });
  });
});
```

- [ ] **Step 2: Run test to verify it fails**

Run: `make pwa.test.unit c='tests/context/shared/dev-tools/infrastructure/ui/SymfonyDebugToolbar.test.tsx'`
Expected: FAIL — cannot resolve `SymfonyDebugToolbar`.

- [ ] **Step 3: Write minimal implementation**

```tsx
"use client";

import { useEffect, useRef } from "react";
import { telemetry } from "@/context/shared/infrastructure/Observability";
import { apiScope } from "@/context/shared/domain/Observability/TelemetryScope";
import type { DebugTokenObserver } from "@/context/shared/domain/DebugToken/DebugTokenObserver";
import { useLatestDebugToken } from "./useLatestDebugToken";

/** Dev-only Symfony endpoint serving the toolbar loader (already routed via FrankenPHP). */
const WDT_PATH = "/_dev/wdt-loader";

/**
 * Recreates a parsed node tree into `host`, replacing each `<script>` with a
 * freshly-created element so the browser executes it (parsed/cloned scripts are
 * inert by spec). Avoids `innerHTML` / `dangerouslySetInnerHTML`: the toolbar
 * fragment is dev-only, same-origin, trusted Symfony output.
 */
function mountFragment(host: HTMLElement, html: string): void {
  while (host.firstChild) host.removeChild(host.firstChild);
  const parsed = new DOMParser().parseFromString(html, "text/html");
  for (const node of Array.from(parsed.body.childNodes)) {
    host.appendChild(reviveNode(node));
  }
}

function reviveNode(node: Node): Node {
  if (node.nodeName === "SCRIPT") {
    const original = node as HTMLScriptElement;
    const script = document.createElement("script");
    for (const attr of Array.from(original.attributes)) {
      script.setAttribute(attr.name, attr.value);
    }
    script.textContent = original.textContent;
    return script;
  }
  const clone = node.cloneNode(false);
  for (const child of Array.from(node.childNodes)) {
    clone.appendChild(reviveNode(child));
  }
  return clone;
}

/**
 * Dev-only floating Symfony Web Debug Toolbar for the real PWA. Mounted once in
 * the root layout behind `isDevToolsAvailable()`. On each new profiler token it
 * loads `/_wdt/{token}` and mounts the fragment fixed to the viewport bottom.
 */
export function SymfonyDebugToolbar({ observer }: { observer?: DebugTokenObserver }): React.ReactElement | null {
  const debugToken = useLatestDebugToken(observer);
  const hostRef = useRef<HTMLDivElement>(null);
  const token = debugToken?.token ?? null;

  useEffect(() => {
    const host = hostRef.current;
    if (!host || !token) return;

    let cancelled = false;
    fetch(`${WDT_PATH}/${encodeURIComponent(token)}`, { cache: "no-store" })
      .then((res) => {
        if (!res.ok) throw new Error(`/_wdt responded ${res.status}`);
        return res.text();
      })
      .then((html) => {
        if (!cancelled && hostRef.current) mountFragment(hostRef.current, html);
      })
      .catch((cause: unknown) => {
        telemetry.warn("Failed to load the Symfony debug toolbar fragment", {
          scope: apiScope("wdt"),
          cause,
        });
      });

    return () => {
      cancelled = true;
    };
  }, [token]);

  if (!token) return null;

  return (
    <div
      ref={hostRef}
      data-testid="dev-tools__symfony-toolbar"
      className="symfony-debug-toolbar"
      style={{ position: "fixed", left: 0, right: 0, bottom: 0, zIndex: 2147483646 }}
    />
  );
}
```

- [ ] **Step 4: Run test to verify it passes**

Run: `make pwa.test.unit c='tests/context/shared/dev-tools/infrastructure/ui/SymfonyDebugToolbar.test.tsx'`
Expected: PASS (3 tests).

- [ ] **Step 5: Commit**

```bash
git add pwa/src/context/shared/dev-tools/infrastructure/ui/SymfonyDebugToolbar.tsx pwa/tests/context/shared/dev-tools/infrastructure/ui/SymfonyDebugToolbar.test.tsx
git commit -m "feat(dev-tools): add SymfonyDebugToolbar component"
```

---

## Task 9b (CONTINGENT — only if Task 1 chose Approach B)

Skip entirely if the spike showed `/_wdt/{token}` is self-contained.

**Files:**
- Create: `api/config/routes/dev.yaml` entry (file exists from PR #260 — append, do not recreate).
- Create: `api/src/...` controller **only if** `TemplateController` cannot render the loader (prefer a route-only solution first).

- [ ] **Step 1: Add a dev-only loader route**

Append to `api/config/routes/dev.yaml` (already `when@dev`-scoped from PR #260). Render Symfony's own toolbar loader template for the token via the framework's `TemplateController` (no domain code):

```yaml
_dev_wdt_loader:
    path: /_dev/wdt-loader/{token}
    controller: Symfony\Bundle\FrameworkBundle\Controller\TemplateController
    defaults:
        template: '@WebProfiler/Profiler/toolbar_js.html.twig'
        context:
            token: ''        # overridden per-request below
    requirements:
        token: '[\w-]+'
```

If `TemplateController` cannot pass the route `{token}` into the template context (its `context` is static), instead add a tiny dev-only controller that renders `toolbar_js.html.twig` with `['token' => $token, 'position' => 'bottom', 'excluded_ajax_paths' => '...']`. Keep it under a `When@dev`-guarded service; it is framework glue, not domain.

- [ ] **Step 2: Point the component at the loader route**

In `SymfonyDebugToolbar.tsx`, change `const WDT_PATH = "/_wdt";` to `const WDT_PATH = "/_dev/wdt-loader";` and re-run Task 9 Step 4 (the test asserts the path — update the expected URL in the test to match).

- [ ] **Step 3: Verify Caddy routes `/_dev/wdt-loader*` to Symfony**

`api/frankenphp/Caddyfile` already excludes `/_dev*` from the `@pwa` matcher, so no change is needed. Confirm with:
```bash
curl -ks "https://localhost:$PORT/_dev/wdt-loader/$TOKEN" | head -c 500
```
Expected: the toolbar loader JS.

- [ ] **Step 4: PHP checks + commit**

```bash
make php.stan
make php.quality
git add api/config/routes/dev.yaml
git commit -m "feat(api): dev-only Symfony toolbar loader route for the PWA"
```

---

## Task 10: Mount the toolbar in the root layout

**Files:**
- Modify: `pwa/src/app/layout.tsx`

No new unit test (Server Component composition). Covered by manual verification (Task 11) and the existing prod-exclusion guarantees (Tasks 4/7). The mount is gated by `isDevToolsAvailable()`.

- [ ] **Step 1: Add the gated mount**

In `layout.tsx`, add the imports:

```typescript
import { isDevToolsAvailable } from "@/context/shared/dev-tools/domain/isDevToolsAvailable";
import { SymfonyDebugToolbar } from "@/context/shared/dev-tools/infrastructure/ui/SymfonyDebugToolbar";
```

Inside `<body>`, after `<SonnerToaster />` and still inside `<ThemeProvider>`, render the toolbar only outside production:

```tsx
          <AuthProvider>{children}</AuthProvider>
          <SonnerToaster />
          {isDevToolsAvailable() ? <SymfonyDebugToolbar /> : null}
```

Because `isDevToolsAvailable()` reduces to `process.env.NODE_ENV !== "production"` (inlined by Next at build time), the prod bundle dead-code-eliminates the branch.

- [ ] **Step 2: Type check + lint**

Run: `make pwa.quality`
Expected: PASS.

- [ ] **Step 3: Run the full PWA unit suite**

Run: `make pwa.test.unit`
Expected: PASS — including the existing guards (`tests/data-testid-uniqueness.test.ts` sees the new unique `dev-tools__symfony-toolbar` id; `tests/next-public-env-allowlist.test.ts` unaffected — no new `NEXT_PUBLIC_*`).

- [ ] **Step 4: Commit**

```bash
git add pwa/src/app/layout.tsx
git commit -m "feat(dev-tools): mount the Symfony debug toolbar in the PWA (dev only)"
```

---

## Task 11: Manual browser verification + CSP check

**Files:** none (verification); possibly `pwa/next.config.ts` only if a CSP gap surfaces.

- [ ] **Step 1: Ensure the worktree stack is running**

```bash
make docker.up
make docker.info   # note the HTTPS $PORT
```

- [ ] **Step 2: Load a real PWA route in a browser**

Open `https://localhost:$PORT/backoffice/banks` (accept the self-signed dev cert manually — per root `CLAUDE.md`, do not downgrade to curl-only for the visual check). Expected: the Symfony Web Debug Toolbar renders fixed at the bottom and reflects the banks `/api/*` request; its link opens `/_profiler/{token}`.

- [ ] **Step 3: Check the browser console for CSP violations**

Expected: no `Content-Security-Policy` violation reports for the toolbar's inline scripts/styles/fetch. If a concrete violation appears, add the minimal directive **inside the existing dev-only `isProd` branch** in `pwa/next.config.ts#headers()` (never in the prod CSP), commit it as `fix(pwa): allow <directive> for the dev debug toolbar`, and add a line to the CSP section of the spec + `pwa/CLAUDE.md`. If none appears (expected), record "no CSP change required" in the PR description.

- [ ] **Step 4: Confirm production exclusion**

```bash
make pwa.production.build
```
Then grep the build output for the toolbar id to confirm it is not emitted:
```bash
grep -rl "dev-tools__symfony-toolbar" pwa/.next 2>/dev/null || echo "absent from prod build (expected)"
```
Expected: `absent from prod build (expected)` (the `isDevToolsAvailable()` branch is dead-code-eliminated).

- [ ] **Step 5: Commit any CSP change (only if Step 3 required one)**

If no change was needed, nothing to commit here.

---

## Task 12: Docs, full quality gate, and PR

**Files:**
- Modify: `pwa/CLAUDE.md` (add the toolbar to "Shared building blocks" / Dev Tools module).
- Modify: `docs/architecture-pwa.md` (note the dev-only toolbar seam).
- Modify: `docs/claude-code-quickref.md` (only if a new `src/` directory needs a layout-table entry — `DebugToken/` under `context/shared/`).

- [ ] **Step 1: Document the building block**

In `pwa/CLAUDE.md`, under the **Dev Tools module** bullet, add a sub-bullet:

```markdown
  - **Symfony debug toolbar (real PWA)** — `<SymfonyDebugToolbar>` (mounted once in `app/layout.tsx` behind `isDevToolsAvailable()`) reads the per-request `X-Debug-Token` published by `FetchHttpClient` through the `DebugTokenObserver` port (`context/shared/domain/DebugToken/`; dev adapter `EventTargetDebugTokenObserver`, prod no-op), then loads Symfony's `/_wdt/{token}` fragment same-origin. Dev/test only; absent from prod builds.
```

- [ ] **Step 2: Note the seam in the architecture doc**

Add a short paragraph to `docs/architecture-pwa.md` (module-boundaries section) describing the `DebugToken` port → `FetchHttpClient` publisher → dev-only toolbar flow, cross-linking the spec.

- [ ] **Step 3: Run the full quality + test gates**

Run from the worktree root:
```bash
make pwa.quality
make pwa.test.unit
```
Expected: both PASS. (E2E is run in CI per the repo's local-e2e ownership constraint; do not block on host Playwright.)

If Task 9b ran (Approach B), also:
```bash
make php.stan
make php.quality
```
Expected: PASS.

- [ ] **Step 4: Delete the spec if its work is fully shipped**

Per repo convention, a spec whose intent is spent is removed once the work lands. Keep the spec until the PR is approved; the PR description carries the record. (Do **not** delete in this task — note it as a post-merge cleanup.)

- [ ] **Step 5: Security self-review (mandatory)**

Walk the root `CLAUDE.md` frontend checklist against the diff. Key points to assert in the PR body:
- No `dangerouslySetInnerHTML` / `innerHTML` / `eval`: the toolbar uses `DOMParser` + `appendChild` + script revival on dev-only, same-origin, trusted Symfony output.
- No new `NEXT_PUBLIC_*` var; no `connect-src`/`script-src` prod widening (CSP either unchanged or dev-only addition).
- Toolbar fetch URL is a fixed same-origin path; `token` is `encodeURIComponent`-encoded.
- Production exclusion proven (Task 11 Step 4).

- [ ] **Step 6: Push and open the PR**

```bash
git push -u origin feat/shared-pwa-debug-toolbar-10pm
gh pr create --title "feat(shared): surface the Symfony debug toolbar inside the PWA" \
  --body "Closes #262. <summary + security review + 'no CSP change required' / 'dev-only CSP addition' note>"
```
Do **not** merge — prepare the PR and stop (protected `main`, per-merge permission required).

---

## Self-review (already run against the spec)

- **Spec coverage:** data flow → Tasks 2–10; production exclusion → Tasks 4, 7, 10 + verification Task 11 Step 4; CSP "verify don't change" → Task 11 Step 3; error handling (telemetry warn) → Task 9 Step 3; testing matrix → Tasks 4–9; API zero-change + Approach B contingency → Tasks 1, 9b. All covered.
- **Type consistency:** `DebugToken { token, profilerUrl }`, observer `publish`/`subscribe(): () => void`, Inversify key `"DebugTokenObserver"`, header names `X-Debug-Token` / `X-Debug-Token-Link`, testid `dev-tools__symfony-toolbar` — used identically across every task.
- **No placeholders:** every code step shows complete code; the only conditional is Task 9b, gated explicitly on the Task 1 spike with full code provided.
