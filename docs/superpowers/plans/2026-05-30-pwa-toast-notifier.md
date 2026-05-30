# PWA Toast Notifier Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Add a reusable, swappable toast notification system to the PWA, exposed as a domain port with a Sonner adapter, mounted once in the root layout.

**Architecture:** Mirror the existing `DateTimeProvider` pattern — a pure port in `domain/`, a singleton adapter in `infrastructure/`. The Sonner adapter has two co-located halves: the trigger (`SonnerToastNotifier`) and the viewport (`SonnerToaster`). Naming encodes the channel (Toast) and the implementation (Sonner) so future channels (`Banner`/`Push`) and a future library-free adapter coexist without a rename.

**Tech Stack:** Next.js 16 (App Router), TypeScript (strict), Sonner, Vitest, Tailwind 4.

---

## Spec

`docs/superpowers/specs/2026-05-30-pwa-toast-notifier-design.md`

## Conventions for every command

- Run from the worktree root: `/home/dev/Projects/ERPify/.claude/worktrees/feat+pwa-toast-notifier`.
- **Docker-stack caveat (project memory):** `make pwa.*` targets exec inside the running container, which mounts whatever checkout started the stack. Before running any `make pwa.*` target here, point the stack at this worktree (`make docker.down` then `make app.dev` from this worktree root), otherwise the commands run against the main checkout. If you prefer host-local runs, `cd pwa && npm run test:unit -- <file>` works once `pwa/node_modules` is installed.
- Single unit-test file (canonical): `make pwa.test.unit c='<path>'`.
- Lint/format sweep (required at end): `make pwa.quality`.

## File Structure

| File | Responsibility |
|------|----------------|
| `pwa/package.json` / `pwa/package-lock.json` | Add `sonner` dependency. |
| `pwa/src/context/shared/domain/Notification/NotificationLevel.ts` | Shared level vocabulary (`success`/`error`/`info`/`warning`). |
| `pwa/src/context/shared/domain/Notification/Toast/ToastNotifier.ts` | The toast-channel **port** + `ToastOptions`. Pure, no Sonner types. |
| `pwa/src/context/shared/infrastructure/Notification/Toast/SonnerToastNotifier.ts` | Sonner **adapter** (trigger): wraps `toast.*`, maps options. |
| `pwa/src/context/shared/infrastructure/Notification/Toast/index.ts` | Singleton `toastNotifier: ToastNotifier`. |
| `pwa/src/context/shared/infrastructure/Notification/Toast/SonnerToaster.tsx` | Sonner **adapter** (viewport): `"use client"`, mounts `<Toaster>`. |
| `pwa/src/app/layout.tsx` | Mount `<SonnerToaster />` once in `<body>`. |
| `pwa/tests/context/shared/domain/Notification/NotificationLevel.test.ts` | Level enumeration test. |
| `pwa/tests/context/shared/infrastructure/Notification/Toast/SonnerToastNotifier.test.ts` | Adapter behaviour (sonner mocked) + singleton surface. |
| `pwa/CLAUDE.md` | Document the new shared building block. |
| `docs/architecture-pwa.md` | Note the `Notification` module (if module list present). |

---

## Task 1: Add the Sonner dependency

**Files:**
- Modify: `pwa/package.json`
- Modify: `pwa/package-lock.json`

- [ ] **Step 1: Install sonner**

Run (from worktree root):

```bash
cd pwa && npm install sonner --save && cd ..
```

Expected: `sonner` appears under `dependencies` in `pwa/package.json`; `pwa/package-lock.json` updated.

- [ ] **Step 2: Verify audit is clean**

Run:

```bash
cd pwa && npm audit --omit=dev && cd ..
```

Expected: no new vulnerabilities introduced by `sonner`. If the host leaves a root-owned empty `pwa/node_modules`, follow the `make pwa.install` note in `pwa/CLAUDE.md`.

- [ ] **Step 3: Commit**

```bash
git add pwa/package.json pwa/package-lock.json
git commit -m "build(pwa): add sonner dependency for toast notifications"
```

---

## Task 2: NotificationLevel domain type

**Files:**
- Create: `pwa/src/context/shared/domain/Notification/NotificationLevel.ts`
- Test: `pwa/tests/context/shared/domain/Notification/NotificationLevel.test.ts`

- [ ] **Step 1: Write the failing test**

Create `pwa/tests/context/shared/domain/Notification/NotificationLevel.test.ts`:

```ts
import { describe, expect, it } from "vitest";
import { NotificationLevel } from "@/context/shared/domain/Notification/NotificationLevel";

describe("NotificationLevel", () => {
  it("enumerates the four supported levels", () => {
    expect(Object.values(NotificationLevel)).toEqual([
      "success",
      "error",
      "info",
      "warning",
    ]);
  });
});
```

- [ ] **Step 2: Run test to verify it fails**

Run: `make pwa.test.unit c='tests/context/shared/domain/Notification/NotificationLevel.test.ts'`
Expected: FAIL — cannot resolve `@/context/shared/domain/Notification/NotificationLevel`.

- [ ] **Step 3: Write minimal implementation**

Create `pwa/src/context/shared/domain/Notification/NotificationLevel.ts`:

```ts
/**
 * Severity levels shared by every notification channel (toast, and future
 * banner / push). Kept as a const object + union so adding a level does not
 * change channel interfaces, mirroring the project's other domain enums.
 */
export const NotificationLevel = {
  Success: "success",
  Error: "error",
  Info: "info",
  Warning: "warning",
} as const;

export type NotificationLevel =
  (typeof NotificationLevel)[keyof typeof NotificationLevel];
```

- [ ] **Step 4: Run test to verify it passes**

Run: `make pwa.test.unit c='tests/context/shared/domain/Notification/NotificationLevel.test.ts'`
Expected: PASS.

- [ ] **Step 5: Commit**

```bash
git add pwa/src/context/shared/domain/Notification/NotificationLevel.ts \
        pwa/tests/context/shared/domain/Notification/NotificationLevel.test.ts
git commit -m "feat(pwa): add NotificationLevel domain vocabulary"
```

---

## Task 3: ToastNotifier port

**Files:**
- Create: `pwa/src/context/shared/domain/Notification/Toast/ToastNotifier.ts`

No standalone runtime test — this is a pure TypeScript interface, verified by
the compiler and exercised by the adapter test in Task 4. (TDD applies to the
adapter, which is the first unit with behaviour.)

- [ ] **Step 1: Create the port**

Create `pwa/src/context/shared/domain/Notification/Toast/ToastNotifier.ts`:

```ts
/**
 * `ToastNotifier` is the domain port for the **toast** notification channel.
 *
 * It exposes one method per {@link NotificationLevel} and hides every adapter
 * detail: no `sonner` types leak across this boundary, so the implementation
 * (Sonner today, a custom library-free toaster tomorrow) can be swapped
 * without touching domain or application code. A future `Banner` / `Push`
 * channel gets its own sibling port under `domain/Notification/`.
 */
export interface ToastOptions {
  /** Secondary line rendered under the message. */
  description?: string;
  /** Auto-dismiss duration in milliseconds; the adapter maps it to its unit. */
  durationMs?: number;
  /** Stable id for de-duplication (adapter-defined semantics). */
  id?: string;
}

export interface ToastNotifier {
  success(message: string, options?: ToastOptions): void;
  error(message: string, options?: ToastOptions): void;
  info(message: string, options?: ToastOptions): void;
  warning(message: string, options?: ToastOptions): void;
}
```

- [ ] **Step 2: Type-check**

Run: `cd pwa && npx tsc --noEmit && cd ..`
Expected: no errors.

- [ ] **Step 3: Commit**

```bash
git add pwa/src/context/shared/domain/Notification/Toast/ToastNotifier.ts
git commit -m "feat(pwa): add ToastNotifier domain port"
```

---

## Task 4: SonnerToastNotifier adapter + singleton

**Files:**
- Create: `pwa/src/context/shared/infrastructure/Notification/Toast/SonnerToastNotifier.ts`
- Create: `pwa/src/context/shared/infrastructure/Notification/Toast/index.ts`
- Test: `pwa/tests/context/shared/infrastructure/Notification/Toast/SonnerToastNotifier.test.ts`

- [ ] **Step 1: Write the failing test**

Create `pwa/tests/context/shared/infrastructure/Notification/Toast/SonnerToastNotifier.test.ts`:

```ts
import { beforeEach, describe, expect, it, vi } from "vitest";
import { toast } from "sonner";
import { SonnerToastNotifier } from "@/context/shared/infrastructure/Notification/Toast/SonnerToastNotifier";
import { toastNotifier } from "@/context/shared/infrastructure/Notification/Toast";

vi.mock("sonner", () => ({
  toast: {
    success: vi.fn(),
    error: vi.fn(),
    info: vi.fn(),
    warning: vi.fn(),
  },
}));

describe("SonnerToastNotifier", () => {
  const notifier = new SonnerToastNotifier();

  beforeEach(() => {
    vi.clearAllMocks();
  });

  it("forwards success to toast.success with undefined options", () => {
    notifier.success("Saved");
    expect(toast.success).toHaveBeenCalledWith("Saved", undefined);
  });

  it("forwards info and warning to the matching sonner method", () => {
    notifier.info("Heads up");
    notifier.warning("Careful");
    expect(toast.info).toHaveBeenCalledWith("Heads up", undefined);
    expect(toast.warning).toHaveBeenCalledWith("Careful", undefined);
  });

  it("maps ToastOptions to the sonner option shape", () => {
    notifier.error("Boom", { description: "details", durationMs: 5000, id: "x" });
    expect(toast.error).toHaveBeenCalledWith("Boom", {
      description: "details",
      duration: 5000,
      id: "x",
    });
  });
});

describe("toastNotifier singleton", () => {
  it("exposes the full ToastNotifier surface", () => {
    expect(typeof toastNotifier.success).toBe("function");
    expect(typeof toastNotifier.error).toBe("function");
    expect(typeof toastNotifier.info).toBe("function");
    expect(typeof toastNotifier.warning).toBe("function");
  });
});
```

- [ ] **Step 2: Run test to verify it fails**

Run: `make pwa.test.unit c='tests/context/shared/infrastructure/Notification/Toast/SonnerToastNotifier.test.ts'`
Expected: FAIL — cannot resolve the adapter / index modules.

- [ ] **Step 3: Write the adapter**

Create `pwa/src/context/shared/infrastructure/Notification/Toast/SonnerToastNotifier.ts`:

```ts
import { toast } from "sonner";
import type {
  ToastNotifier,
  ToastOptions,
} from "@/context/shared/domain/Notification/Toast/ToastNotifier";

/**
 * Sonner-backed {@link ToastNotifier}. The only file in the app that imports
 * `sonner` for triggering toasts — swapping libraries means replacing this
 * file (and its viewport sibling {@link SonnerToaster}) only.
 */
export class SonnerToastNotifier implements ToastNotifier {
  success(message: string, options?: ToastOptions): void {
    toast.success(message, this.map(options));
  }

  error(message: string, options?: ToastOptions): void {
    toast.error(message, this.map(options));
  }

  info(message: string, options?: ToastOptions): void {
    toast.info(message, this.map(options));
  }

  warning(message: string, options?: ToastOptions): void {
    toast.warning(message, this.map(options));
  }

  private map(
    options?: ToastOptions,
  ): { description?: string; duration?: number; id?: string } | undefined {
    if (!options) return undefined;
    return {
      description: options.description,
      duration: options.durationMs,
      id: options.id,
    };
  }
}
```

- [ ] **Step 4: Write the singleton**

Create `pwa/src/context/shared/infrastructure/Notification/Toast/index.ts`:

```ts
import type { ToastNotifier } from "@/context/shared/domain/Notification/Toast/ToastNotifier";
import { SonnerToastNotifier } from "./SonnerToastNotifier";

/**
 * Default {@link ToastNotifier} for the application. Consumers MUST type the
 * import as the `ToastNotifier` port, never as the concrete adapter, so the
 * implementation can be swapped without churn (mirrors `dateTimeProvider`).
 */
export const toastNotifier: ToastNotifier = new SonnerToastNotifier();

export type {
  ToastNotifier,
  ToastOptions,
} from "@/context/shared/domain/Notification/Toast/ToastNotifier";
```

- [ ] **Step 5: Run test to verify it passes**

Run: `make pwa.test.unit c='tests/context/shared/infrastructure/Notification/Toast/SonnerToastNotifier.test.ts'`
Expected: PASS (4 tests).

- [ ] **Step 6: Commit**

```bash
git add pwa/src/context/shared/infrastructure/Notification/Toast/SonnerToastNotifier.ts \
        pwa/src/context/shared/infrastructure/Notification/Toast/index.ts \
        pwa/tests/context/shared/infrastructure/Notification/Toast/SonnerToastNotifier.test.ts
git commit -m "feat(pwa): add Sonner toast adapter and toastNotifier singleton"
```

---

## Task 5: SonnerToaster viewport

**Files:**
- Create: `pwa/src/context/shared/infrastructure/Notification/Toast/SonnerToaster.tsx`

This is a thin, configuration-only client wrapper around Sonner's `<Toaster>`.
It is exercised end-to-end by the PR 2 bank-delete E2E; no isolated unit test is
added (rendering a third-party portal in jsdom adds no signal). It carries no
hardcoded `data-testid` (the testid-uniqueness guard forbids that in shared
components).

- [ ] **Step 1: Create the viewport**

Create `pwa/src/context/shared/infrastructure/Notification/Toast/SonnerToaster.tsx`:

```tsx
"use client";

import { Toaster, type ToasterProps } from "sonner";

/**
 * Sonner viewport mounted once in the root layout. The render half of the
 * Sonner adapter — co-located with {@link SonnerToastNotifier} so the whole
 * Sonner implementation lives together. Defaults can be overridden via props.
 *
 * - `position="bottom-right"` — app-wide placement.
 * - `richColors` — tonal styling per level, aligned with the StatusBadge palette.
 * - `closeButton` — Sonner renders it with an accessible name ("Close toast").
 */
export function SonnerToaster(props: Readonly<ToasterProps>) {
  return <Toaster position="bottom-right" richColors closeButton {...props} />;
}
```

- [ ] **Step 2: Type-check**

Run: `cd pwa && npx tsc --noEmit && cd ..`
Expected: no errors.

- [ ] **Step 3: Commit**

```bash
git add pwa/src/context/shared/infrastructure/Notification/Toast/SonnerToaster.tsx
git commit -m "feat(pwa): add SonnerToaster viewport component"
```

---

## Task 6: Mount the toaster in the root layout

**Files:**
- Modify: `pwa/src/app/layout.tsx`

Current `<body>` (around line 47):

```tsx
      <body suppressHydrationWarning>{children}</body>
```

- [ ] **Step 1: Add the import**

In `pwa/src/app/layout.tsx`, add with the other imports (top of file, after the existing `@/lib/...` imports):

```tsx
import { SonnerToaster } from "@/context/shared/infrastructure/Notification/Toast/SonnerToaster";
```

- [ ] **Step 2: Mount the viewport**

Replace the `<body>` line:

```tsx
      <body suppressHydrationWarning>
        {children}
        <SonnerToaster />
      </body>
```

- [ ] **Step 3: Type-check**

Run: `cd pwa && npx tsc --noEmit && cd ..`
Expected: no errors.

- [ ] **Step 4: Commit**

```bash
git add pwa/src/app/layout.tsx
git commit -m "feat(pwa): mount SonnerToaster in the root layout"
```

---

## Task 7: Document the shared building block

**Files:**
- Modify: `pwa/CLAUDE.md`
- Modify: `docs/architecture-pwa.md`

- [ ] **Step 1: Add a building-block entry to `pwa/CLAUDE.md`**

In `pwa/CLAUDE.md`, under the "Shared building blocks (use these, don't reinvent)" list, add a new bullet after the **Error module** entry:

```markdown
- **Toast notifications** — `toastNotifier` from
  `@/context/shared/infrastructure/Notification/Toast`, typed as the
  `ToastNotifier` port (never as the concrete `SonnerToastNotifier`). Call
  `toastNotifier.success("…")` / `.error` / `.info` / `.warning` from any
  client component for transient feedback; pass `{ description, durationMs,
  id }` via `ToastOptions`. The Sonner adapter (`SonnerToastNotifier` trigger +
  `SonnerToaster` viewport) is co-located under
  `src/context/shared/infrastructure/Notification/Toast/`; the viewport is
  mounted once in `app/layout.tsx`. Messages are plain strings rendered as
  escaped text — never pass HTML. To swap libraries, replace the two Sonner
  files and the singleton; the port and call sites stay put. Future channels
  (`Banner`/`Push`) are siblings under `domain/Notification/`.
```

- [ ] **Step 2: Note the module in `docs/architecture-pwa.md`**

In `docs/architecture-pwa.md`, where shared modules / `context/shared` are described, add a sentence:

```markdown
The `Notification` module (`context/shared/{domain,infrastructure}/Notification/`)
provides transient user feedback. Its first channel is **Toast**: the
`ToastNotifier` port with a Sonner adapter (`SonnerToastNotifier` +
`SonnerToaster`, mounted once in the root layout) and the `toastNotifier`
singleton. The naming leaves room for additional channels (`Banner`, `Push`)
and alternative adapters without renaming the port.
```

If `docs/architecture-pwa.md` has no shared-module section to slot this into,
add it under the closest "shared / cross-cutting" heading and note the deviation
in the commit body.

- [ ] **Step 3: Commit**

```bash
git add pwa/CLAUDE.md docs/architecture-pwa.md
git commit -m "docs(pwa): document the toast notifier building block"
```

---

## Task 8: Final verification sweep

**Files:** none (verification only)

- [ ] **Step 1: Run the full PWA unit suite**

Run: `make pwa.test.unit`
Expected: PASS, including the two new test files; no regressions.

- [ ] **Step 2: Run the data-testid uniqueness guard**

Run: `make pwa.test.unit c='tests/data-testid-uniqueness.test.ts'`
Expected: PASS — the new shared components add no hardcoded `data-testid`.

- [ ] **Step 3: Lint + format sweep**

Run: `make pwa.quality`
Expected: ESLint + Prettier clean. If the fixers mutate files, re-stage and amend the relevant commit.

- [ ] **Step 4: Production build smoke (catches layout/SSR issues)**

Run: `make pwa.production.build`
Expected: build succeeds with `<SonnerToaster>` mounted in the layout.

- [ ] **Step 5: Confirm clean tree**

Run: `git status --short`
Expected: empty (everything committed).

---

## Self-Review (completed during planning)

- **Spec coverage:** port (Task 3), `NotificationLevel` (Task 2), Sonner adapter + singleton (Task 4), viewport (Task 5), layout mount (Task 6), dependency (Task 1), security/text-only rendering (covered by string-only port + docs), tests (Tasks 2, 4, 8), docs (Task 7). Non-goals (Banner/Push, CustomToastNotifier, promise/dismiss, next-themes, consumer wiring) are intentionally absent.
- **Placeholder scan:** no TBD/TODO; every code step shows complete code.
- **Type consistency:** `ToastNotifier`, `ToastOptions` (`description` / `durationMs` / `id`), `NotificationLevel`, `toastNotifier`, `SonnerToastNotifier`, `SonnerToaster` are used identically across tasks; the adapter `map()` output matches the sonner shape asserted in the Task 4 test.

## Out of Scope → PR 2 (separate spec/plan)

Bank-delete UX fix: close the detail-page refetch race so "Bank not found" never
flashes, redirect cleanly to the list, and show
`toastNotifier.success("Banco eliminado")` after redirect, with E2E coverage.
