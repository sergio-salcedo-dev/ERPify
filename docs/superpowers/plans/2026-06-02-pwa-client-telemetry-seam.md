# PWA Client Telemetry Seam + Shared Mercure Realtime Hook — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Make Mercure realtime failures observable in staging (silent in prod) via a swappable client telemetry port, extract a reusable `useMercureRealtime` hook for future entities, and rename `NEXT_PUBLIC_SYMFONY_API_BASE_URL` → `NEXT_PUBLIC_API_BASE_URL`.

**Architecture:** A `Telemetry` domain port + `ConsoleTelemetry` adapter + `telemetry` singleton (mirrors `Notification/Toast`). The adapter emits to `console` only for `dev`/`staging` (read from a new public `NEXT_PUBLIC_APP_ENV`), silent in prod until Sentry/Datadog adapters land. The bank-specific realtime orchestration moves into a generic `useMercureRealtime` hook so every future entity inherits authorize + telemetry behavior.

**Tech Stack:** Next.js 16, TypeScript (strict), React `useEffectEvent`, Vitest + `@testing-library/react` (jsdom), Docker Compose build args.

**Spec:** `docs/superpowers/specs/2026-06-02-pwa-client-telemetry-seam-design.md`

**Commits:** The spec named two; this plan uses four focused commits (rename / telemetry seam / mercure hook+rewire / docs) on `feat/pwa-client-telemetry-seam` for finer bisectability — same branch, same net result. **Always stage explicitly — never `git add -A`** (the working tree has an unrelated dirty `_bmad-output/...deferred-work.md` that must stay out of every commit).

---

## File Structure

| File | Responsibility | Action |
| --- | --- | --- |
| `pwa/src/context/shared/domain/types/appEnv.ts` | `AppEnv` const + type (pure, mirrors `nodeEnv.ts`) | Create |
| `pwa/src/context/shared/domain/Observability/Telemetry.ts` | `Telemetry` port + `TelemetryContext` | Create |
| `pwa/src/context/shared/infrastructure/Observability/ConsoleTelemetry.ts` | Console adapter with per-env gate | Create |
| `pwa/src/context/shared/infrastructure/Observability/index.ts` | `telemetry` singleton + type re-exports | Create |
| `pwa/tests/context/shared/infrastructure/Observability/ConsoleTelemetry.test.ts` | Per-env emit matrix | Create |
| `pwa/src/context/shared/infrastructure/RealTime/useMercureRealtime.ts` | Generic authorize→subscribe→telemetry hook | Create |
| `pwa/tests/context/shared/infrastructure/RealTime/useMercureRealtime.test.tsx` | Authorize-failure telemetry + no-op-on-empty | Create |
| `pwa/src/context/backoffice/bank/infrastructure/bankRealtime.ts` | Bank-specific bits + thin `useBankRealtime` | Modify (shrink) |
| `pwa/src/context/shared/infrastructure/RealTime/BrowserMercureSubscriber.ts` | Route malformed-payload swallow through telemetry | Modify |
| `pwa/Dockerfile`, `compose*.yaml`, `pwa/.env.example`, `.env.prod.example` | `NEXT_PUBLIC_APP_ENV` plumbing | Modify |
| 30 files repo-wide | env-var rename | Modify (Task 1) |
| `pwa/CLAUDE.md`, `docs/architecture-pwa.md` | docs | Modify (Task 9) |

---

## Task 0: Clean baseline

**Files:** Modify: `pwa/src/context/backoffice/bank/infrastructure/bankRealtime.ts` (revert uncommitted experimental edit)

- [ ] **Step 1: Confirm branch + see the dirty working tree**

Run: `cd /home/dev/Projects/ERPify && git branch --show-current && git status --short`
Expected: branch `feat/pwa-client-telemetry-seam`; `bankRealtime.ts` and `_bmad-output/...deferred-work.md` show as modified.

- [ ] **Step 2: Revert ONLY the experimental bankRealtime edit (it's superseded by this plan)**

Run: `git checkout -- pwa/src/context/backoffice/bank/infrastructure/bankRealtime.ts`
Expected: `git status --short` no longer lists `bankRealtime.ts`. Leave `_bmad-output/...` untouched — it is unrelated and stays out of all commits.

- [ ] **Step 3: Verify the baseline file is back to HEAD**

Run: `git diff --stat pwa/src/context/backoffice/bank/infrastructure/bankRealtime.ts`
Expected: no output (file matches HEAD).

(No commit — this only restores baseline.)

---

## Task 1: Rename `NEXT_PUBLIC_SYMFONY_API_BASE_URL` → `NEXT_PUBLIC_API_BASE_URL`

**Files:** Modify: 30 files repo-wide (code, Dockerfile/compose, `make/deploy.mk`, `.env.example`s, docs). Excludes `_bmad-output/`.

> Note: the distinct var `PLAYWRIGHT_SYMFONY_API_BASE_URL` (in `playwright.config.ts` / `pwa/.env.example`) is NOT renamed — the literal token differs, so the replace below leaves it alone.

- [ ] **Step 1: Apply the global rename (excludes node_modules/vendor/.next/_bmad-output)**

```bash
cd /home/dev/Projects/ERPify
rg -l --hidden -g '!**/node_modules/**' -g '!**/vendor/**' -g '!**/.next/**' -g '!_bmad-output/**' \
  "NEXT_PUBLIC_SYMFONY_API_BASE_URL" \
  | xargs sed -i 's/NEXT_PUBLIC_SYMFONY_API_BASE_URL/NEXT_PUBLIC_API_BASE_URL/g'
```

- [ ] **Step 2: Verify zero occurrences remain (outside _bmad-output)**

```bash
rg --hidden -g '!**/node_modules/**' -g '!**/vendor/**' -g '!**/.next/**' -g '!_bmad-output/**' \
  "NEXT_PUBLIC_SYMFONY_API_BASE_URL" && echo "STILL PRESENT" || echo "clean"
```
Expected: `clean`.

- [ ] **Step 3: Confirm the renamed name is present where expected**

```bash
rg -l "NEXT_PUBLIC_API_BASE_URL" pwa/src pwa/Dockerfile compose.yaml compose.prod.yaml make/deploy.mk
```
Expected: lists `pwa/src/...HttpClient.ts`, `...BrowserMercureSubscriber.ts`, `...bankRealtime.ts`, `pwa/src/lib/frankenphp-hot-reload.ts`, `pwa/next.config.ts`, `pwa/Dockerfile`, `compose.yaml`, `compose.prod.yaml`, `make/deploy.mk`.

- [ ] **Step 4: Lint + run the affected unit test**

Run: `make pwa.quality`
Expected: `EXIT 0` (no ESLint/Prettier errors).
Run: `make pwa.test.unit c='tests/lib/frankenphp-hot-reload.test.ts'`
Expected: PASS (the `vi.stubEnv("NEXT_PUBLIC_API_BASE_URL", ...)` calls now match the code).

- [ ] **Step 5: Commit (stage only renamed files — never `git add -A`)**

```bash
git add $(rg -l --hidden -g '!**/node_modules/**' -g '!**/vendor/**' -g '!**/.next/**' -g '!_bmad-output/**' "NEXT_PUBLIC_API_BASE_URL")
git status --short    # confirm _bmad-output/ is NOT staged
git commit -m "$(cat <<'EOF'
refactor(pwa): rename NEXT_PUBLIC_SYMFONY_API_BASE_URL to NEXT_PUBLIC_API_BASE_URL

Co-Authored-By: Claude Opus 4.8 (1M context) <noreply@anthropic.com>
EOF
)"
git show --stat --oneline HEAD | head -40
```
Expected: ~30 files changed; `_bmad-output/` absent.

---

## Task 2: `AppEnv` domain constant

**Files:** Create: `pwa/src/context/shared/domain/types/appEnv.ts`

- [ ] **Step 1: Create the file**

```ts
/**
 * Deployment environment values for the PWA, surfaced to the browser via the
 * public build-time `NEXT_PUBLIC_APP_ENV` var. Distinct from {@link NodeEnv}
 * (which collapses staging into "production"): `NODE_ENV` is always
 * `production` in the built image, so it cannot tell staging from prod. Spelled
 * once here so call sites compare against the const, never a raw literal.
 */
export const AppEnv = {
  DEVELOPMENT: "dev",
  STAGING: "staging",
  PRODUCTION: "prod",
} as const;

export type AppEnv = (typeof AppEnv)[keyof typeof AppEnv];
```

- [ ] **Step 2: Type-check via lint**

Run: `make pwa.quality`
Expected: `EXIT 0`.

(Committed together with Task 4.)

---

## Task 3: `Telemetry` domain port

**Files:** Create: `pwa/src/context/shared/domain/Observability/Telemetry.ts`

- [ ] **Step 1: Create the port**

```ts
/**
 * `Telemetry` is the domain port for client-side observability (diagnostics
 * that are never user-facing). It hides every adapter detail so the
 * implementation — `console` today, Sentry / Datadog tomorrow — can be swapped
 * without touching call sites (mirrors `ToastNotifier` / `DateTimeProvider`).
 */
export interface TelemetryContext {
  /** Low-cardinality scope tag, e.g. "realtime:bank". */
  scope?: string;
  /** Triggering error / cause. Adapters serialize + scrub it; never assume PII-free. */
  cause?: unknown;
}

export interface Telemetry {
  warn(message: string, context?: TelemetryContext): void;
  error(message: string, context?: TelemetryContext): void;
}
```

- [ ] **Step 2: Type-check via lint**

Run: `make pwa.quality`
Expected: `EXIT 0`.

(Committed together with Task 4.)

---

## Task 4: `ConsoleTelemetry` adapter + singleton (TDD)

**Files:**
- Create: `pwa/src/context/shared/infrastructure/Observability/ConsoleTelemetry.ts`
- Create: `pwa/src/context/shared/infrastructure/Observability/index.ts`
- Test: `pwa/tests/context/shared/infrastructure/Observability/ConsoleTelemetry.test.ts`

- [ ] **Step 1: Write the failing test**

```ts
import { afterEach, describe, expect, it, vi } from "vitest";
import { ConsoleTelemetry } from "@/context/shared/infrastructure/Observability/ConsoleTelemetry";

afterEach(() => {
  vi.unstubAllEnvs();
  vi.restoreAllMocks();
});

describe("ConsoleTelemetry", () => {
  it("emits warn with scope + cause in dev", () => {
    vi.stubEnv("NEXT_PUBLIC_APP_ENV", "dev");
    const spy = vi.spyOn(console, "warn").mockImplementation(() => {});
    const cause = new Error("x");
    new ConsoleTelemetry().warn("boom", { scope: "realtime:bank", cause });
    expect(spy).toHaveBeenCalledWith("[realtime:bank] boom", cause);
  });

  it("emits in staging", () => {
    vi.stubEnv("NEXT_PUBLIC_APP_ENV", "staging");
    const spy = vi.spyOn(console, "error").mockImplementation(() => {});
    new ConsoleTelemetry().error("nope", { scope: "s" });
    expect(spy).toHaveBeenCalledOnce();
  });

  it("is silent in prod", () => {
    vi.stubEnv("NEXT_PUBLIC_APP_ENV", "prod");
    const warn = vi.spyOn(console, "warn").mockImplementation(() => {});
    const error = vi.spyOn(console, "error").mockImplementation(() => {});
    const t = new ConsoleTelemetry();
    t.warn("a", { scope: "s" });
    t.error("b", { scope: "s" });
    expect(warn).not.toHaveBeenCalled();
    expect(error).not.toHaveBeenCalled();
  });

  it("defaults unknown env to silent (prod)", () => {
    vi.stubEnv("NEXT_PUBLIC_APP_ENV", "");
    const warn = vi.spyOn(console, "warn").mockImplementation(() => {});
    new ConsoleTelemetry().warn("a");
    expect(warn).not.toHaveBeenCalled();
  });

  it("omits the cause arg when absent and falls back to a default scope", () => {
    vi.stubEnv("NEXT_PUBLIC_APP_ENV", "dev");
    const spy = vi.spyOn(console, "warn").mockImplementation(() => {});
    new ConsoleTelemetry().warn("no cause");
    expect(spy).toHaveBeenCalledWith("[telemetry] no cause");
  });
});
```

- [ ] **Step 2: Run test to verify it fails**

Run: `make pwa.test.unit c='tests/context/shared/infrastructure/Observability/ConsoleTelemetry.test.ts'`
Expected: FAIL — cannot resolve `@/context/shared/infrastructure/Observability/ConsoleTelemetry`.

- [ ] **Step 3: Implement the adapter**

`pwa/src/context/shared/infrastructure/Observability/ConsoleTelemetry.ts`:
```ts
import { AppEnv } from "@/context/shared/domain/types/appEnv";
import type { Telemetry, TelemetryContext } from "@/context/shared/domain/Observability/Telemetry";

/**
 * Console adapter for the {@link Telemetry} port. Emits to the browser console
 * only in `dev` / `staging` (read from the public `NEXT_PUBLIC_APP_ENV`); stays
 * silent in `prod` and for any unknown value, so real users never see
 * diagnostics. Prod observability arrives later via Sentry / Datadog adapters
 * behind the same port. Read at call time so the env can be stubbed in tests.
 */
function consoleEnabled(): boolean {
  const env = process.env.NEXT_PUBLIC_APP_ENV;
  return env === AppEnv.DEVELOPMENT || env === AppEnv.STAGING;
}

function format(scope: string | undefined, message: string): string {
  return `[${scope ?? "telemetry"}] ${message}`;
}

export class ConsoleTelemetry implements Telemetry {
  warn(message: string, context?: TelemetryContext): void {
    if (!consoleEnabled()) {
      return;
    }
    const line = format(context?.scope, message);
    if (context?.cause !== undefined) {
      console.warn(line, context.cause);
    } else {
      console.warn(line);
    }
  }

  error(message: string, context?: TelemetryContext): void {
    if (!consoleEnabled()) {
      return;
    }
    const line = format(context?.scope, message);
    if (context?.cause !== undefined) {
      console.error(line, context.cause);
    } else {
      console.error(line);
    }
  }
}
```

- [ ] **Step 4: Create the singleton barrel**

`pwa/src/context/shared/infrastructure/Observability/index.ts`:
```ts
import type { Telemetry } from "@/context/shared/domain/Observability/Telemetry";
import { ConsoleTelemetry } from "./ConsoleTelemetry";

/**
 * Default {@link Telemetry} for the application. Consumers MUST type the import
 * as the `Telemetry` port, never the concrete adapter, so Sentry / Datadog can
 * be swapped in without churn (mirrors `toastNotifier` / `dateTimeProvider`).
 */
export const telemetry: Telemetry = new ConsoleTelemetry();

export type { Telemetry, TelemetryContext } from "@/context/shared/domain/Observability/Telemetry";
```

- [ ] **Step 5: Run test to verify it passes**

Run: `make pwa.test.unit c='tests/context/shared/infrastructure/Observability/ConsoleTelemetry.test.ts'`
Expected: PASS (5 tests).

- [ ] **Step 6: Lint**

Run: `make pwa.quality`
Expected: `EXIT 0` (`no-console` allows `warn`/`error`).

(Committed together with Task 5.)

---

## Task 5: `NEXT_PUBLIC_APP_ENV` plumbing + commit telemetry seam

**Files:** Modify: `pwa/Dockerfile`, `compose.yaml`, `compose.dev.yaml`, `compose.prod.yaml`, `pwa/.env.example`, `.env.prod.example`

- [ ] **Step 1: Dockerfile — add ARG + ENV after the base-URL pair**

In `pwa/Dockerfile`, immediately after the (renamed) `ENV NEXT_PUBLIC_API_BASE_URL=...` line, add:
```dockerfile
ARG NEXT_PUBLIC_APP_ENV=dev
ENV NEXT_PUBLIC_APP_ENV=${NEXT_PUBLIC_APP_ENV}
```

- [ ] **Step 2: `compose.yaml` — pwa build args**

Under `pwa.build.args`, after the `NEXT_PUBLIC_API_BASE_URL:` line, add:
```yaml
        NEXT_PUBLIC_APP_ENV: ${APP_ENV:-dev}
```

- [ ] **Step 3: `compose.dev.yaml` — pwa runtime env (dev runs `next dev`)**

Under `pwa.environment` (which has `NODE_ENV: development`), add:
```yaml
      NEXT_PUBLIC_APP_ENV: dev
```
And under `pwa.build.args` (after `NEXT_PUBLIC_API_BASE_URL:`) add:
```yaml
        NEXT_PUBLIC_APP_ENV: ${APP_ENV:-dev}
```

- [ ] **Step 4: `compose.prod.yaml` — pwa build args (staging sets APP_ENV=staging)**

Under `pwa.build.args`, after the `NEXT_PUBLIC_API_BASE_URL:` line, add:
```yaml
        NEXT_PUBLIC_APP_ENV: ${APP_ENV:-prod}
```

- [ ] **Step 5: `pwa/.env.example` — document the new var**

After the `NEXT_PUBLIC_API_BASE_URL="https://localhost"` block, add:
```bash
# Deployment env surfaced to the browser (dev | staging | prod). Drives client
# telemetry verbosity: dev/staging log diagnostics to the console, prod is silent.
NEXT_PUBLIC_APP_ENV="dev"
```

- [ ] **Step 6: `.env.prod.example` — document the new var**

After the `NEXT_PUBLIC_API_BASE_URL=...` line, add:
```bash
# Browser-visible deployment env (dev | staging | prod). Set to `staging` on the
# staging host to enable client console diagnostics; `prod` keeps them silent.
NEXT_PUBLIC_APP_ENV=prod
```

- [ ] **Step 7: Lint + full unit run**

Run: `make pwa.quality`
Expected: `EXIT 0`.
Run: `make pwa.test.unit`
Expected: all PASS (incl. new `ConsoleTelemetry` tests).

- [ ] **Step 8: Commit the telemetry seam (stage explicitly)**

```bash
git add pwa/src/context/shared/domain/types/appEnv.ts \
        pwa/src/context/shared/domain/Observability/Telemetry.ts \
        pwa/src/context/shared/infrastructure/Observability/ConsoleTelemetry.ts \
        pwa/src/context/shared/infrastructure/Observability/index.ts \
        pwa/tests/context/shared/infrastructure/Observability/ConsoleTelemetry.test.ts \
        pwa/Dockerfile compose.yaml compose.dev.yaml compose.prod.yaml \
        pwa/.env.example .env.prod.example
git commit -m "$(cat <<'EOF'
feat(pwa): add client telemetry port with env-gated console adapter

Telemetry domain port + ConsoleTelemetry adapter + singleton, gated on the new
public NEXT_PUBLIC_APP_ENV: dev/staging emit to console, prod stays silent until
Sentry/Datadog adapters land behind the same port.

Co-Authored-By: Claude Opus 4.8 (1M context) <noreply@anthropic.com>
EOF
)"
git show --stat --oneline HEAD | head -20
```

---

## Task 6: `useMercureRealtime` shared hook (TDD)

**Files:**
- Create: `pwa/src/context/shared/infrastructure/RealTime/useMercureRealtime.ts`
- Test: `pwa/tests/context/shared/infrastructure/RealTime/useMercureRealtime.test.tsx`

- [ ] **Step 1: Write the failing test**

```tsx
import { renderHook, waitFor } from "@testing-library/react";
import { afterEach, describe, expect, it, vi } from "vitest";
import { useMercureRealtime } from "@/context/shared/infrastructure/RealTime/useMercureRealtime";
import { telemetry } from "@/context/shared/infrastructure/Observability";

afterEach(() => {
  vi.restoreAllMocks();
  vi.unstubAllGlobals();
});

describe("useMercureRealtime", () => {
  it("routes an authorize failure through telemetry.warn", async () => {
    vi.stubGlobal("fetch", vi.fn().mockResolvedValue({ ok: false, status: 401 }));
    const warn = vi.spyOn(telemetry, "warn").mockImplementation(() => {});

    renderHook(() =>
      useMercureRealtime<unknown>({
        topics: ["urn:test:topic"],
        authorizePath: "/api/v1/test/realtime/authorize",
        parse: () => null,
        onEvent: () => {},
        scope: "realtime:test",
      }),
    );

    await waitFor(() =>
      expect(warn).toHaveBeenCalledWith("subscription skipped", {
        scope: "realtime:test",
        cause: expect.any(Error),
      }),
    );
  });

  it("does nothing when there are no topics", () => {
    const fetchSpy = vi.fn();
    vi.stubGlobal("fetch", fetchSpy);

    renderHook(() =>
      useMercureRealtime<unknown>({
        topics: [],
        authorizePath: "/x",
        parse: () => null,
        onEvent: () => {},
        scope: "realtime:test",
      }),
    );

    expect(fetchSpy).not.toHaveBeenCalled();
  });
});
```

- [ ] **Step 2: Run test to verify it fails**

Run: `make pwa.test.unit c='tests/context/shared/infrastructure/RealTime/useMercureRealtime.test.tsx'`
Expected: FAIL — cannot resolve `useMercureRealtime`.

- [ ] **Step 3: Implement the hook**

`pwa/src/context/shared/infrastructure/RealTime/useMercureRealtime.ts`:
```ts
"use client";

import { useEffect, useEffectEvent } from "react";
import { telemetry } from "@/context/shared/infrastructure/Observability";
import { mercureSubscriber } from "@/context/shared/infrastructure/RealTime/BrowserMercureSubscriber";

export interface UseMercureRealtimeOptions<E> {
  /** Mercure topic IRIs to subscribe to. No-op when empty. */
  topics: readonly string[];
  /** Entity-specific authorize endpoint that mints the subscriber cookie. */
  authorizePath: string;
  /** Parses a raw event payload into a typed event, or null when unusable. */
  parse: (data: unknown) => E | null;
  /** Invoked with each parsed event. Always sees the latest closure. */
  onEvent: (event: E) => void;
  /** Low-cardinality telemetry scope, e.g. "realtime:bank". */
  scope: string;
}

async function authorize(authorizePath: string): Promise<void> {
  // Resolve an absolute URL against the current origin so `fetch` behaves like
  // the EventSource subscription (a bare relative path is unparseable outside a
  // browser, e.g. under test/SSR).
  const base = (process.env.NEXT_PUBLIC_API_BASE_URL ?? "").replace(/\/$/, "");
  const origin = globalThis.window?.location.origin ?? "http://localhost";
  const url = new URL(`${base}${authorizePath}`, origin);
  const response = await fetch(url, { credentials: "include", cache: "no-store" });
  if (!response.ok) {
    // Reject so the caller skips opening a doomed stream (the hub never delivers
    // private topics without a valid subscriber cookie).
    throw new Error(`Mercure authorize failed: ${response.status}`);
  }
}

/**
 * Subscribes to Mercure topics, authorizes (mints the subscriber cookie) before
 * opening the stream, and dispatches typed events to `onEvent`. Re-mints the
 * cookie on stream error so the EventSource's automatic reconnect stays
 * authorized. Reusable across every entity's realtime feed; failures are routed
 * through `telemetry` (never user-facing). No-op on the server and when `topics`
 * is empty.
 */
export function useMercureRealtime<E>({
  topics,
  authorizePath,
  parse,
  onEvent,
  scope,
}: UseMercureRealtimeOptions<E>): void {
  // `topicsKey` keeps the EventSource open across unrelated re-renders; it
  // changes exactly when the topic set changes (topic IRIs never contain "|").
  const topicsKey = topics.join("|");

  // Effect Events always see the latest `parse` / `onEvent` without being effect
  // dependencies, so changing handler identity each render never tears the stream.
  const dispatch = useEffectEvent((data: unknown): void => {
    const event = parse(data);
    if (event !== null) {
      onEvent(event);
    }
  });

  const refreshAuthorization = useEffectEvent((): void => {
    // Best-effort re-mint on reconnect; swallow + report, never throw.
    void authorize(authorizePath).catch((error) =>
      telemetry.warn("subscriber-cookie refresh failed", { scope, cause: error }),
    );
  });

  useEffect(() => {
    if (!topicsKey || globalThis.window === undefined) {
      return;
    }

    const topicList = topicsKey.split("|");
    let subscription: { close(): void } | undefined;
    let cancelled = false;

    void (async (): Promise<void> => {
      try {
        await authorize(authorizePath);
        if (!cancelled) {
          subscription = mercureSubscriber.subscribe(topicList, (data) => dispatch(data), {
            onError: () => refreshAuthorization(),
          });
        }
      } catch (error) {
        // Best-effort: a missing cookie, an absent EventSource (SSR/test), or a
        // transient network error must never surface as an unhandled rejection.
        telemetry.warn("subscription skipped", { scope, cause: error });
      }
    })();

    return (): void => {
      cancelled = true;
      subscription?.close();
    };
    // `authorizePath` / `scope` are stable per call site, so this preserves the
    // "topicsKey is effectively the only trigger" behavior.
  }, [topicsKey, authorizePath, scope]);
}
```

- [ ] **Step 4: Run test to verify it passes**

Run: `make pwa.test.unit c='tests/context/shared/infrastructure/RealTime/useMercureRealtime.test.tsx'`
Expected: PASS (2 tests).

- [ ] **Step 5: Lint**

Run: `make pwa.quality`
Expected: `EXIT 0` (no `react-hooks/exhaustive-deps` warning — `dispatch`/`refreshAuthorization` are Effect Events, deps are `[topicsKey, authorizePath, scope]`).

(Committed together with Task 8.)

---

## Task 7: Rewire `bankRealtime.ts` onto the shared hook

**Files:** Modify: `pwa/src/context/backoffice/bank/infrastructure/bankRealtime.ts`

- [ ] **Step 1: Replace the file body**

Replace the entire contents of `pwa/src/context/backoffice/bank/infrastructure/bankRealtime.ts` with:
```ts
"use client";

import { Bank, type BankPrimitives } from "@/context/backoffice/bank/domain/Bank";
import { API_ENDPOINTS } from "@/context/shared/infrastructure/api/ApiEndpoints";
import { useMercureRealtime } from "@/context/shared/infrastructure/RealTime/useMercureRealtime";

/**
 * Mercure topic IRIs for back-office banks. MUST stay in lock-step with the API
 * `Erpify\Backoffice\Bank\Domain\MercureBankTopic`.
 */
export const bankTopics = {
  collection: "urn:erpify:backoffice:banks",
  detail: (id: string): string => `urn:erpify:backoffice:bank:${id}`,
} as const;

export type BankRealtimeEvent =
  | { kind: "created"; bank: Bank }
  | { kind: "updated"; bank: Bank }
  | { kind: "deleted"; id: string };

export interface BankRealtimeHandlers {
  onCreated?: (bank: Bank) => void;
  onUpdated?: (bank: Bank) => void;
  onDeleted?: (id: string) => void;
}

function isBankPrimitives(value: unknown): value is BankPrimitives {
  if (typeof value !== "object" || value === null) {
    return false;
  }
  const v = value as Record<string, unknown>;
  return (
    typeof v.id === "string" &&
    typeof v.name === "string" &&
    typeof v.shortName === "string" &&
    typeof v.createdAt === "string" &&
    typeof v.updatedAt === "string"
  );
}

/** Parses a raw Mercure payload into a typed bank event, or null when unusable. */
export function parseBankRealtimeEvent(data: unknown): BankRealtimeEvent | null {
  if (typeof data !== "object" || data === null || !("type" in data)) {
    return null;
  }
  const payload = data as { type: unknown; bank?: unknown; id?: unknown };
  switch (payload.type) {
    case "bank.created":
      return isBankPrimitives(payload.bank)
        ? { kind: "created", bank: Bank.fromPrimitives(payload.bank) }
        : null;
    case "bank.updated":
      return isBankPrimitives(payload.bank)
        ? { kind: "updated", bank: Bank.fromPrimitives(payload.bank) }
        : null;
    case "bank.deleted":
      return typeof payload.id === "string" ? { kind: "deleted", id: payload.id } : null;
    default:
      return null;
  }
}

/**
 * Subscribes to the given bank Mercure topics and dispatches typed events to the
 * provided handlers. Delegates authorize / subscribe / telemetry to the shared
 * {@link useMercureRealtime} hook; this wrapper only owns the bank-specific
 * topic parse + handler mapping.
 */
export function useBankRealtime(topics: readonly string[], handlers: BankRealtimeHandlers): void {
  useMercureRealtime<BankRealtimeEvent>({
    topics,
    authorizePath: API_ENDPOINTS.BACKOFFICE.BANKS.REALTIME_AUTHORIZE,
    parse: parseBankRealtimeEvent,
    onEvent: (event) => {
      if (event.kind === "created") {
        handlers.onCreated?.(event.bank);
      } else if (event.kind === "updated") {
        handlers.onUpdated?.(event.bank);
      } else {
        handlers.onDeleted?.(event.id);
      }
    },
    scope: "realtime:bank",
  });
}
```

- [ ] **Step 2: Verify the existing parser test still passes**

Run: `make pwa.test.unit c='tests/context/backoffice/bank/infrastructure/bankRealtime.test.ts'`
Expected: PASS (7 tests — `parseBankRealtimeEvent` is unchanged and still exported).

- [ ] **Step 3: Lint**

Run: `make pwa.quality`
Expected: `EXIT 0`.

(Committed together with Task 8.)

---

## Task 8: Route malformed-payload swallow through telemetry + commit hook work

**Files:** Modify: `pwa/src/context/shared/infrastructure/RealTime/BrowserMercureSubscriber.ts`

- [ ] **Step 1: Import the telemetry singleton**

At the top of `BrowserMercureSubscriber.ts`, after the existing `import type { ... } from ".../MercureSubscriber"` block, add:
```ts
import { telemetry } from "@/context/shared/infrastructure/Observability";
```

- [ ] **Step 2: Replace the silent malformed-payload catch**

Find:
```ts
    source.onmessage = (event: MessageEvent<string>): void => {
      try {
        onMessage(JSON.parse(event.data));
      } catch {
        // Ignore malformed payloads; the next valid event reconciles state.
      }
    };
```
Replace the `catch` with:
```ts
    source.onmessage = (event: MessageEvent<string>): void => {
      try {
        onMessage(JSON.parse(event.data));
      } catch (error) {
        // Malformed payload — the next valid event reconciles state. Report for
        // diagnostics (never user-facing); shared across every entity's stream.
        telemetry.warn("malformed realtime payload", { scope: "realtime:mercure", cause: error });
      }
    };
```

- [ ] **Step 3: Lint + run the realtime + telemetry tests**

Run: `make pwa.quality`
Expected: `EXIT 0`.
Run: `make pwa.test.unit c='tests/context/shared/infrastructure/RealTime/BrowserMercureSubscriber.test.ts'`
Expected: PASS (existing subscriber tests still green — if any asserts on the malformed path with no console output, it still holds because telemetry is silent in test env).

- [ ] **Step 4: Commit the mercure hook + rewire**

```bash
git add pwa/src/context/shared/infrastructure/RealTime/useMercureRealtime.ts \
        pwa/tests/context/shared/infrastructure/RealTime/useMercureRealtime.test.tsx \
        pwa/src/context/backoffice/bank/infrastructure/bankRealtime.ts \
        pwa/src/context/shared/infrastructure/RealTime/BrowserMercureSubscriber.ts
git commit -m "$(cat <<'EOF'
feat(pwa): extract shared useMercureRealtime hook routing failures to telemetry

bankRealtime shrinks to bank-specific parse + handler mapping; authorize,
subscribe, reconnect re-auth, and the two swallowed failures (+ malformed
payloads in the subscriber) now report via the Telemetry port, so every future
Mercure-backed entity inherits the behavior for free.

Co-Authored-By: Claude Opus 4.8 (1M context) <noreply@anthropic.com>
EOF
)"
git show --stat --oneline HEAD | head -20
```

---

## Task 9: Documentation

**Files:** Modify: `pwa/CLAUDE.md`, `docs/architecture-pwa.md`

- [ ] **Step 1: `pwa/CLAUDE.md` — Env section**

Replace the `## Env` body line referencing the API base URL with the renamed var and the new one:
```markdown
- **Docker stack** (default): `NEXT_PUBLIC_API_BASE_URL=https://localhost`, `SYMFONY_INTERNAL_URL=http://php:80` (set in Compose).
- `NEXT_PUBLIC_APP_ENV` (`dev` | `staging` | `prod`) — public, non-secret, baked at build (`pwa/Dockerfile` ARG from `${APP_ENV}`). Drives client telemetry verbosity; `NODE_ENV` cannot distinguish staging from prod (the image is always `production`).
```

- [ ] **Step 2: `pwa/CLAUDE.md` — decision-rule table**

In the "Where shared code goes" table, the `context/shared/infrastructure/<Module>/` row's Examples cell — append `Observability/Telemetry` to the existing list.

- [ ] **Step 3: `pwa/CLAUDE.md` — Shared building blocks**

Add a bullet after the Toast block:
```markdown
- **Client telemetry** — `telemetry` from
  `@/context/shared/infrastructure/Observability`, typed as the `Telemetry`
  port (never the concrete `ConsoleTelemetry`). Call `telemetry.warn(msg, {
  scope, cause })` / `.error(...)` for non-user-facing diagnostics. The console
  adapter emits only in `dev`/`staging` (via `NEXT_PUBLIC_APP_ENV`) and is
  silent in prod; future Sentry/Datadog adapters slot in behind the same port.
  Realtime hooks already route through it via `useMercureRealtime`.
```

- [ ] **Step 4: `docs/architecture-pwa.md` — note the seam + shared hook**

Add a short subsection (near the realtime / shared-infrastructure discussion) describing: the `Telemetry` port + `ConsoleTelemetry` adapter (env-gated, prod-silent, Sentry/Datadog-ready), and the generic `useMercureRealtime` hook that entity-specific realtime hooks (`useBankRealtime`) delegate to. Keep links to concrete files only (no directory/glob hrefs, per the markdown link rule).

- [ ] **Step 5: Lint docs + commit**

Run: `make pwa.quality`
Expected: `EXIT 0`.
```bash
git add pwa/CLAUDE.md docs/architecture-pwa.md
git commit -m "$(cat <<'EOF'
docs(pwa): document telemetry seam, useMercureRealtime, and env var rename

Co-Authored-By: Claude Opus 4.8 (1M context) <noreply@anthropic.com>
EOF
)"
```

---

## Final verification

- [ ] **Step 1: Full PWA quality + unit suite**

Run: `make pwa.quality && make pwa.test.unit`
Expected: `EXIT 0`; all unit tests pass (incl. new `ConsoleTelemetry` + `useMercureRealtime` tests).

- [ ] **Step 2: Confirm clean history + no stray files**

Run: `git log --oneline main..HEAD` and `git status --short`
Expected: four feature commits (rename / telemetry seam / mercure hook / docs); working tree clean except the pre-existing unrelated `_bmad-output/...deferred-work.md` (never staged).

- [ ] **Step 3: Security self-review (per root CLAUDE.md)**

Confirm: no secrets logged (telemetry `cause` carries an HTTP status / network error only; prod is silent regardless); `NEXT_PUBLIC_APP_ENV` is non-secret/public by design; no new `dangerouslySetInnerHTML`/`eval`; CSP `connect-src` unchanged (no external sink yet). Note in the PR description.

- [ ] **Step 4 (operator, out-of-repo — call out in PR):** rename `NEXT_PUBLIC_SYMFONY_API_BASE_URL` → `NEXT_PUBLIC_API_BASE_URL` in `pwa/.env.local` and any VPS/CI secret store, and set `NEXT_PUBLIC_APP_ENV` (`staging` on staging hosts). `compose.prod.yaml` hard-fails on the old name's absence.

- [ ] **Step 5 (optional manual staging check):** build the pwa image with `NEXT_PUBLIC_APP_ENV=staging`, force a Mercure authorize failure (e.g. block `/banks/realtime/authorize`), and confirm `[realtime:bank] subscription skipped` appears in the browser console; rebuild with `prod` and confirm silence.

---

## Self-Review (author checklist — completed)

- **Spec coverage:** Part A → Tasks 2–5; Part B → Tasks 6–8 (incl. malformed-payload bonus, Task 8); Part C rename → Task 1; `NEXT_PUBLIC_APP_ENV` plumbing → Task 5; tests → Tasks 4, 6 + preserved parser test (Task 7 Step 2); docs → Task 9; operator/out-of-repo + security → Final verification. All covered.
- **Placeholders:** none — every code step shows complete code; commands have expected output.
- **Type consistency:** `Telemetry`/`TelemetryContext` (Task 3) used identically in adapter (4), hook (6), subscriber (8); `AppEnv` (2) used in adapter (4); `useMercureRealtime<E>` signature (6) matches its call in `useBankRealtime` (7); env var `NEXT_PUBLIC_API_BASE_URL` consistent post-Task-1 in hook (6).
- **Test boundary note:** following the existing codebase pattern, the React hook gets one focused behavioral test (authorize-failure → telemetry, plus empty-topics no-op) rather than full EventSource integration coverage; the pure `parseBankRealtimeEvent` keeps its 7 existing tests.
