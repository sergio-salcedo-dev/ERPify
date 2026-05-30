# PWA Toast Notifier — Design Spec

**Date:** 2026-05-30
**Scope:** `pwa/` only
**Branch:** `feat/pwa-toast-notifier` (base `main`)
**Status:** Approved design, pending implementation plan

## Context

The reported problem is the **bad UX when deleting a bank from its detail page**:
after confirming the delete, the detail page (a Client Component) re-runs its
`FindBank` fetch for the now-deleted id, gets an HTTP 404, and flashes the
"Bank not found" `EmptyState` before the `router.push(list)` navigation
completes.

That bug is a **race condition** and is fixed by suppressing the refetch during
a pending delete plus a clean redirect. It is **NOT** what this spec covers.

The PWA currently has **no toast / notification system**. We decided to build
that reusable infrastructure **first, as its own PR**, and then a **second PR**
will fix the bank-delete UX by both closing the race *and* showing a success
toast ("Banco eliminado") on the list after redirect.

**This spec covers only the toast system (PR 1).** The bank-delete fix (PR 2)
is out of scope here and gets its own spec → plan → implementation cycle.

## Goals

- A reusable, swappable toast notification mechanism for any client component.
- Naming/structure that encodes two independent axes so it scales:
  1. **Channel / presentation** = *Toast* (room for future `Banner`, `Push`, …).
  2. **Adapter / implementation** = *Sonner* (room for a future custom,
     library-free toast adapter that can coexist).
- Follows the existing **port (domain) + singleton adapter (infrastructure)**
  convention, mirroring `DateTimeProvider`.

## Non-Goals (YAGNI)

- No `Banner` / `Push` channels — only the folder/naming structure is left ready.
- No `CustomToastNotifier` (library-free adapter) — `SonnerToastNotifier` only.
- No `promise` / `loading` toast helper, no `dismiss`/`update` API.
- No `next-themes` integration (the project does not use it).
- **No consumer wiring** — the bank-delete fix is PR 2, not here.

## Architecture

Mirrors `DateTimeProvider`: a pure **port** in `domain/`, a **singleton
adapter** in `infrastructure/`. The Sonner adapter has two halves — the
**trigger** (`SonnerToastNotifier`, fires `toast.*`) and the **viewport**
(`SonnerToaster`, mounts Sonner's `<Toaster>`). Both halves are Sonner-specific
infrastructure, so they are **co-located** under
`infrastructure/Notification/Toast/`, not in `components/ui/` (the viewport is
mounted exactly once and is not a reusable design-system primitive).

```
pwa/src/context/shared/
  domain/Notification/
    NotificationLevel.ts            # shared: "success"|"error"|"info"|"warning"
    Toast/
      ToastNotifier.ts              # PORT: success/error/info/warning + ToastOptions
  infrastructure/Notification/Toast/
    SonnerToastNotifier.ts          # ADAPTER (trigger): wraps Sonner toast.*
    SonnerToaster.tsx               # ADAPTER (viewport): "use client", mounts <Toaster>
    index.ts                        # export const toastNotifier: ToastNotifier = new SonnerToastNotifier()

pwa/src/app/layout.tsx              # mounts <SonnerToaster /> once in <body>
```

**Why this naming scales:**

- `Notification/` is the family namespace. Future channels are siblings:
  `domain/Notification/Banner/BannerNotifier.ts`, `.../Push/PushNotifier.ts` —
  no rename required.
- `ToastNotifier` makes the channel (toast presentation) explicit, not a generic
  "notifier".
- `SonnerToastNotifier` / `SonnerToaster` make the implementation (Sonner)
  explicit. A future `CustomToastNotifier` (library-free) coexists as another
  adapter of the **same** `ToastNotifier` port; only `index.ts` and the
  `layout.tsx` import change to swap it.
- `NotificationLevel` is a shared vocabulary reusable by any future channel (a
  banner also has success/error/info/warning), so the levels are not duplicated.

## Port Contract

```ts
// domain/Notification/NotificationLevel.ts
export const NotificationLevel = {
  Success: "success",
  Error: "error",
  Info: "info",
  Warning: "warning",
} as const;
export type NotificationLevel =
  (typeof NotificationLevel)[keyof typeof NotificationLevel];

// domain/Notification/Toast/ToastNotifier.ts
export interface ToastOptions {
  /** Secondary line under the title. */
  description?: string;
  /** Auto-dismiss duration in milliseconds. Adapter maps to its own unit. */
  durationMs?: number;
  /** Stable id for dedupe (adapter-defined semantics). */
  id?: string;
}

export interface ToastNotifier {
  success(message: string, options?: ToastOptions): void;
  error(message: string, options?: ToastOptions): void;
  info(message: string, options?: ToastOptions): void;
  warning(message: string, options?: ToastOptions): void;
}
```

- Methods return `void` — Sonner's toast id does not leak across the port.
- The port imports **no** Sonner types. Pure domain.

## Adapter

```ts
// infrastructure/Notification/Toast/SonnerToastNotifier.ts
import { toast } from "sonner";
import type { ToastNotifier, ToastOptions }
  from "../../../domain/Notification/Toast/ToastNotifier";

export class SonnerToastNotifier implements ToastNotifier {
  success(message: string, options?: ToastOptions): void {
    toast.success(message, this.map(options));
  }
  // error → toast.error, info → toast.info, warning → toast.warning

  private map(options?: ToastOptions) {
    if (!options) return undefined;
    return {
      description: options.description,
      duration: options.durationMs,
      id: options.id,
    };
  }
}
```

```ts
// infrastructure/Notification/Toast/index.ts
import type { ToastNotifier } from "../../../domain/Notification/Toast/ToastNotifier";
import { SonnerToastNotifier } from "./SonnerToastNotifier";

/** Default ToastNotifier. Consumers MUST type as the ToastNotifier port. */
export const toastNotifier: ToastNotifier = new SonnerToastNotifier();
export type { ToastNotifier, ToastOptions }
  from "../../../domain/Notification/Toast/ToastNotifier";
```

## Viewport

```tsx
// infrastructure/Notification/Toast/SonnerToaster.tsx
"use client";
import { Toaster } from "sonner";
```

- Configured with: `position="bottom-right"`, `richColors`, `closeButton`.
- `closeButton` carries an accessible name (`aria-label` / `title`) per the
  project's action-button a11y rules.
- `toastOptions.classNames` map success/error/warning/info to the project's
  design tokens (`success`, `destructive`, `warning`, `primary`) used by
  `StatusBadge`, so toasts match the existing tone palette.
- No `next-themes`. No hardcoded `data-testid` (the testid-uniqueness guard
  forbids hardcoding in shared components; E2E targets text / `role="status"`).

## Mounting

`pwa/src/app/layout.tsx` renders `<SonnerToaster />` once inside `<body>`, after
`{children}`. Sonner's `toast.*` functions are module-level, so no React Context
/ provider is required — the singleton works from any client component.

## Data Flow

```
client component → toastNotifier.success("…")
  → SonnerToastNotifier.success → toast.success(...)
    → <SonnerToaster> (mounted in layout) renders the toast
```

## Error Handling

- The adapter is a thin pass-through; Sonner manages the toast lifecycle.
- The port accepts only `string` messages → React renders them as **escaped
  text**. No HTML sink, no `dangerouslySetInnerHTML`. Untrusted API strings are
  safe to display as toast titles/descriptions.

## Security Review (this change)

- **XSS:** messages are plain strings rendered as text; no HTML injection sink
  introduced. No `dangerouslySetInnerHTML` / `innerHTML`.
- **Dependencies:** add `sonner`; `npm audit` must be clean; transitive
  ownership verified.
- **Headers/CSP:** Sonner injects inline styles; confirm it works under the
  existing CSP without adding `'unsafe-eval'` or widening `script-src`.
- No secrets, storage, or navigation surface touched.

## Testing

- **Unit (Vitest):** `SonnerToastNotifier` with `sonner` mocked —
  - each method (`success`/`error`/`info`/`warning`) calls the matching
    `toast.*`;
  - `ToastOptions` map correctly (`durationMs → duration`, `description`, `id`);
  - calling with no options passes `undefined`.
  - `index.ts` exports `toastNotifier` typed as the `ToastNotifier` port.
- **E2E:** no isolated E2E here; real coverage arrives with the PR 2 consumer
  (bank delete). `SonnerToaster` exposes no hardcoded testid.

## Docs to Update

- `pwa/CLAUDE.md` → "Shared building blocks" — add a `toastNotifier` / toast
  entry (port + adapter location, usage, the "type as the port" rule).
- `docs/architecture-pwa.md` → note the `Notification` module if module
  boundaries are enumerated there.

## Dependency

- `npm i sonner` (the Shadcn-standard toast library).

## Out of Scope → Follow-up (PR 2)

The bank-delete UX fix is a **separate spec/PR**:

1. Close the race: guard the detail page so a pending/just-completed delete does
   not refetch and flash "Bank not found"; redirect cleanly to the list.
2. Show `toastNotifier.success("Banco eliminado")` after landing on the list.
3. E2E covering: delete from detail → no "Bank not found" flash → list shown →
   success toast visible.
