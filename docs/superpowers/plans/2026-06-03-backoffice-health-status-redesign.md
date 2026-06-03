# BackOffice Health Status Redesign — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Redesign the internal `/backoffice/health` page to the Atlassian-style status idea (aggregate banner + per-component row, auto-loaded on mount with a Refresh) in the backoffice design language, reusing a shared status model and keeping the admin-facing technical error detail.

**Architecture:** Extract the pure `systemStatus` model from the public `/status` route to `@/lib/systemStatus` (decoupled from any `HealthCheck` via a structural type) and reuse it from both pages. The backoffice page renders a token-styled `SystemStatusBanner` and a `<StatusBadge>`-based component row (never the marketing palette), auto-runs `BackOfficeCheckHealth`, surfaces `ProblemDetails` on failure for admins, and reports failures through telemetry with the `api` surface.

**Tech Stack:** Next.js 16 App Router · TypeScript (strict) · Tailwind 4 (design tokens) · `@/components/erpify` (`StatusBadge`, `ProblemDisplay`) · Inversify · lucide-react · Vitest + Testing Library · Playwright.

---

## Context the engineer needs

- **Branch:** `feat/backoffice-health-status` (off `main`), already checked out. The local Docker stack is UP (https://localhost responds; `/api/v1/backoffice/health` returns `{"data":{"status":"ok","service":"Back office",...}}`).
- **Two design languages, do not cross them.** The public `/status` page uses the **marketing** language (raw Tailwind palette, components under `app/status/_components/`). The backoffice uses **design tokens** + `@/components/erpify`. This page is backoffice → tokens. Do NOT import the marketing `StatusBanner` / `ComponentStatusRow`.
- **The `api` telemetry surface already exists on `main`:** `apiScope(detail)` from `@/context/shared/domain/Observability/TelemetryScope` builds `api:<detail>` (e.g. `api:backoffice-health`). `telemetry` is the singleton from `@/context/shared/infrastructure/Observability`; call `telemetry.warn(message, { scope, cause })` — it is silent in prod, visible in dev/staging.
- **The existing health use case** (reuse): `container.get<CheckHealth>("BackOfficeCheckHealth").run()` returns a `HealthCheck { status; service; datetime }` from `@/context/backoffice/health/...`; it calls `GET /api/v1/backoffice/health` and throws `HttpError` (which carries `.problem: ProblemDetails`).
- **Run from repo root.** Make targets exec inside the container. `make pwa.quality` (ESLint + Prettier) is REQUIRED after PWA edits.
- **E2E hits the live stack.** Bring it up with `make app.dev` if needed. On this host (Ubuntu 26.04) Playwright needs `PLAYWRIGHT_HOST_PLATFORM_OVERRIDE=ubuntu24.04-x64` prefixed on e2e runs. If a `.next-e2e` EACCES error appears, run `rm -rf pwa/.next-e2e` once and retry.
- **`data-testid` uniqueness** is enforced by `tests/data-testid-uniqueness.test.ts` over `src/`. New ids here are unique; the old `backoffice-health-status` id is removed.
- **Spec:** `docs/superpowers/specs/2026-06-03-backoffice-health-status-redesign-design.md`.

## File Structure

**Create:**
- `pwa/src/lib/systemStatus.ts` — the shared pure status model (moved from `app/status/_components/`, decoupled from `HealthCheck`).
- `pwa/src/app/backoffice/health/_components/SystemStatusBanner.tsx` — token-styled aggregate banner.
- `pwa/src/app/backoffice/health/_components/HealthComponentRow.tsx` — one component row using `<StatusBadge>`.
- `pwa/tests/lib/systemStatus.test.ts` — moved model test (decoupled).
- `pwa/tests/app/backoffice/health/SystemStatusBanner.test.tsx` — banner render tests.
- `pwa/tests/app/backoffice/health/HealthComponentRow.test.tsx` — row render tests.

**Modify:**
- `pwa/src/app/status/page.tsx`, `pwa/src/app/status/_components/StatusBanner.tsx`, `pwa/src/app/status/_components/ComponentStatusRow.tsx` — import the model from `@/lib/systemStatus`.
- `pwa/tests/app/status/StatusBanner.test.tsx`, `pwa/tests/app/status/ComponentStatusRow.test.tsx` — import `SystemStatus` from `@/lib/systemStatus`.
- `pwa/src/app/backoffice/health/page.tsx` — full redesign.
- `pwa/tests/e2e/helpers/health-assertions.ts` — rewrite `expectBackOfficeHealthOk`.
- `pwa/tests/e2e/backoffice/dashboard.spec.ts` — drop the `Run Health Check` click (auto-load).
- `docs/architecture-pwa.md` — note the redesign + shared model.

**Delete:**
- `pwa/src/app/status/_components/systemStatus.ts` (moved to `@/lib/`).
- `pwa/tests/app/status/systemStatus.test.ts` (moved to `tests/lib/`).

---

### Task 1: Extract the shared status model to `@/lib/systemStatus`

This is a refactor of the existing (already-merged) `/status` feature. It MUST keep `/status` green. The model becomes framework-free and `HealthCheck`-free so both status pages reuse it.

**Files:**
- Create: `pwa/src/lib/systemStatus.ts`
- Delete: `pwa/src/app/status/_components/systemStatus.ts`
- Move: `pwa/tests/app/status/systemStatus.test.ts` → `pwa/tests/lib/systemStatus.test.ts`
- Modify: `pwa/src/app/status/page.tsx`, `pwa/src/app/status/_components/StatusBanner.tsx`, `pwa/src/app/status/_components/ComponentStatusRow.tsx`, `pwa/tests/app/status/StatusBanner.test.tsx`, `pwa/tests/app/status/ComponentStatusRow.test.tsx`

- [ ] **Step 1: Create the decoupled model at the new path**

Create `pwa/src/lib/systemStatus.ts`:

```ts
/** Aggregate UI status for the status pages (Atlassian-style). */
export const SystemStatus = {
  CHECKING: "checking",
  OPERATIONAL: "operational",
  DEGRADED: "degraded",
  DISRUPTED: "disrupted",
} as const;
export type SystemStatus = (typeof SystemStatus)[keyof typeof SystemStatus];

/** Minimal health snapshot the derivation needs — any `HealthCheck` satisfies it. */
export interface HealthSnapshot {
  status: string;
  datetime: string;
}

export interface SystemStatusInput {
  /** A health check is in flight. */
  checking: boolean;
  /** The last check threw (transport / HTTP error). */
  failed: boolean;
  /** The last successful health snapshot, or null when none yet / failed. */
  result: HealthSnapshot | null;
}

export interface SystemStatusView {
  status: SystemStatus;
  /** Server-reported ISO datetime, present only on the success path. */
  datetime: string | null;
}

const HEALTHY = "ok";

export function deriveSystemStatus({
  checking,
  failed,
  result,
}: SystemStatusInput): SystemStatusView {
  if (checking) return { status: SystemStatus.CHECKING, datetime: null };
  if (failed || result === null) return { status: SystemStatus.DISRUPTED, datetime: null };
  return {
    status: result.status === HEALTHY ? SystemStatus.OPERATIONAL : SystemStatus.DEGRADED,
    datetime: result.datetime,
  };
}

/** Headline shown in the aggregate banner. */
export function systemHeadline(status: SystemStatus): string {
  switch (status) {
    case SystemStatus.OPERATIONAL:
      return "All Systems Operational";
    case SystemStatus.DEGRADED:
      return "Partial Service Disruption";
    case SystemStatus.DISRUPTED:
      return "Service Disruption";
    default:
      return "Checking system status…";
  }
}

/** Short label shown in a component's status pill. */
export function componentStatusLabel(status: SystemStatus): string {
  switch (status) {
    case SystemStatus.OPERATIONAL:
      return "Operational";
    case SystemStatus.DEGRADED:
      return "Degraded";
    case SystemStatus.DISRUPTED:
      return "Disrupted";
    default:
      return "Checking…";
  }
}
```

- [ ] **Step 2: Delete the old model file**

Run: `git rm pwa/src/app/status/_components/systemStatus.ts`

- [ ] **Step 3: Repoint every import of the old model**

There are exactly five source/test files importing the old path. Update each:

In `pwa/src/app/status/page.tsx`, change:
```ts
import { deriveSystemStatus } from "./_components/systemStatus";
```
to:
```ts
import { deriveSystemStatus } from "@/lib/systemStatus";
```

In `pwa/src/app/status/_components/StatusBanner.tsx`, change:
```ts
import { SystemStatus, systemHeadline } from "./systemStatus";
```
to:
```ts
import { SystemStatus, systemHeadline } from "@/lib/systemStatus";
```

In `pwa/src/app/status/_components/ComponentStatusRow.tsx`, change:
```ts
import { SystemStatus, componentStatusLabel } from "./systemStatus";
```
to:
```ts
import { SystemStatus, componentStatusLabel } from "@/lib/systemStatus";
```

In `pwa/tests/app/status/StatusBanner.test.tsx`, change:
```ts
import { SystemStatus } from "@/app/status/_components/systemStatus";
```
to:
```ts
import { SystemStatus } from "@/lib/systemStatus";
```

In `pwa/tests/app/status/ComponentStatusRow.test.tsx`, change:
```ts
import { SystemStatus } from "@/app/status/_components/systemStatus";
```
to:
```ts
import { SystemStatus } from "@/lib/systemStatus";
```

- [ ] **Step 4: Confirm no stragglers reference the old path**

Run: `grep -rn "_components/systemStatus" pwa/src pwa/tests || echo "CLEAN: no references to the old path"`
Expected: prints `CLEAN: no references to the old path`.

- [ ] **Step 5: Move the model test and decouple it from `HealthCheck`**

Run: `git rm pwa/tests/app/status/systemStatus.test.ts`

Create `pwa/tests/lib/systemStatus.test.ts`:

```ts
import { describe, expect, it } from "vitest";
import {
  SystemStatus,
  componentStatusLabel,
  deriveSystemStatus,
  systemHeadline,
} from "@/lib/systemStatus";

const okResult = { status: "ok", datetime: "2026-06-02T10:00:00+02:00" };

describe("deriveSystemStatus", () => {
  it("reports CHECKING while a check is in flight", () => {
    expect(deriveSystemStatus({ checking: true, failed: false, result: null })).toEqual({
      status: SystemStatus.CHECKING,
      datetime: null,
    });
  });

  it("reports CHECKING even when a stale result is present (re-check in flight)", () => {
    expect(deriveSystemStatus({ checking: true, failed: false, result: okResult })).toEqual({
      status: SystemStatus.CHECKING,
      datetime: null,
    });
  });

  it("prioritizes CHECKING over a prior failure (re-check after error)", () => {
    expect(deriveSystemStatus({ checking: true, failed: true, result: null })).toEqual({
      status: SystemStatus.CHECKING,
      datetime: null,
    });
  });

  it("reports OPERATIONAL for an ok result and exposes the server datetime", () => {
    expect(deriveSystemStatus({ checking: false, failed: false, result: okResult })).toEqual({
      status: SystemStatus.OPERATIONAL,
      datetime: "2026-06-02T10:00:00+02:00",
    });
  });

  it("reports DEGRADED when the result status is not 'ok'", () => {
    const degraded = { status: "degraded", datetime: "2026-06-02T10:00:00+02:00" };
    expect(deriveSystemStatus({ checking: false, failed: false, result: degraded }).status).toBe(
      SystemStatus.DEGRADED,
    );
  });

  it("reports DISRUPTED when the check failed", () => {
    expect(deriveSystemStatus({ checking: false, failed: true, result: null })).toEqual({
      status: SystemStatus.DISRUPTED,
      datetime: null,
    });
  });

  it("reports DISRUPTED when there is no result and no failure flag", () => {
    expect(deriveSystemStatus({ checking: false, failed: false, result: null }).status).toBe(
      SystemStatus.DISRUPTED,
    );
  });
});

describe("systemHeadline", () => {
  it("maps each status to its banner headline", () => {
    expect(systemHeadline(SystemStatus.OPERATIONAL)).toBe("All Systems Operational");
    expect(systemHeadline(SystemStatus.DEGRADED)).toBe("Partial Service Disruption");
    expect(systemHeadline(SystemStatus.DISRUPTED)).toBe("Service Disruption");
    expect(systemHeadline(SystemStatus.CHECKING)).toBe("Checking system status…");
  });
});

describe("componentStatusLabel", () => {
  it("maps each status to its pill label", () => {
    expect(componentStatusLabel(SystemStatus.OPERATIONAL)).toBe("Operational");
    expect(componentStatusLabel(SystemStatus.DEGRADED)).toBe("Degraded");
    expect(componentStatusLabel(SystemStatus.DISRUPTED)).toBe("Disrupted");
    expect(componentStatusLabel(SystemStatus.CHECKING)).toBe("Checking…");
  });
});
```

- [ ] **Step 6: Run the moved test + the /status component tests + lint**

Run: `make pwa.test.unit c='tests/lib/systemStatus.test.ts tests/app/status/StatusBanner.test.tsx tests/app/status/ComponentStatusRow.test.tsx'`
Expected: PASS (9 + 6 + 3 tests green).
Run: `make pwa.quality`
Expected: PASS. If Prettier complains, run `make pwa.format` then re-check.

- [ ] **Step 7: Commit**

```bash
git add pwa/src/lib/systemStatus.ts pwa/tests/lib/systemStatus.test.ts pwa/src/app/status pwa/tests/app/status
git commit -m "refactor(pwa): extract shared systemStatus model to @/lib for reuse"
```

---

### Task 2: `SystemStatusBanner` (backoffice, token-styled)

**Files:**
- Create: `pwa/src/app/backoffice/health/_components/SystemStatusBanner.tsx`
- Test: `pwa/tests/app/backoffice/health/SystemStatusBanner.test.tsx`

- [ ] **Step 1: Write the failing test**

Create `pwa/tests/app/backoffice/health/SystemStatusBanner.test.tsx`:

```tsx
import { describe, expect, it } from "vitest";
import { render, screen } from "@testing-library/react";
import { SystemStatusBanner } from "@/app/backoffice/health/_components/SystemStatusBanner";
import { SystemStatus } from "@/lib/systemStatus";

describe("SystemStatusBanner", () => {
  it("shows the operational headline and an 'as of' subline with the datetime", () => {
    render(
      <SystemStatusBanner
        status={SystemStatus.OPERATIONAL}
        datetime="2026-06-02T10:00:00+02:00"
        testId="x-banner"
      />,
    );
    const banner = screen.getByTestId("x-banner");
    expect(banner).toHaveTextContent("All Systems Operational");
    expect(banner).toHaveTextContent(/as of/i);
  });

  it("exposes role=status with aria-live and is aria-busy only while checking", () => {
    const { rerender } = render(
      <SystemStatusBanner status={SystemStatus.CHECKING} datetime={null} testId="x-banner" />,
    );
    const banner = screen.getByTestId("x-banner");
    expect(banner).toHaveAttribute("role", "status");
    expect(banner).toHaveAttribute("aria-live", "polite");
    expect(banner).toHaveAttribute("aria-busy", "true");

    rerender(
      <SystemStatusBanner status={SystemStatus.OPERATIONAL} datetime={null} testId="x-banner" />,
    );
    expect(screen.getByTestId("x-banner")).toHaveAttribute("aria-busy", "false");
  });

  it("shows a disruption message and no datetime when disrupted", () => {
    render(<SystemStatusBanner status={SystemStatus.DISRUPTED} datetime={null} testId="x-banner" />);
    const banner = screen.getByTestId("x-banner");
    expect(banner).toHaveTextContent("Service Disruption");
    expect(banner).toHaveTextContent(/trouble reaching this service/i);
    expect(banner).not.toHaveTextContent(/as of/i);
  });

  it("renders an aria-hidden icon", () => {
    const { container } = render(
      <SystemStatusBanner status={SystemStatus.OPERATIONAL} datetime={null} testId="x-banner" />,
    );
    const svg = container.querySelector("svg");
    expect(svg).toHaveAttribute("aria-hidden", "true");
  });
});
```

- [ ] **Step 2: Run the test to verify it fails**

Run: `make pwa.test.unit c='tests/app/backoffice/health/SystemStatusBanner.test.tsx'`
Expected: FAIL — cannot resolve `@/app/backoffice/health/_components/SystemStatusBanner`.

- [ ] **Step 3: Write the implementation**

Create `pwa/src/app/backoffice/health/_components/SystemStatusBanner.tsx`:

```tsx
import React from "react";
import { AlertTriangle, CheckCircle2, Loader2, XCircle, type LucideIcon } from "lucide-react";
import { dateTimeProvider } from "@/context/shared/infrastructure/DateTimeProvider";
import { cn } from "@/lib/utils";
import { SystemStatus, systemHeadline } from "@/lib/systemStatus";

interface SystemStatusBannerProps {
  status: SystemStatus;
  datetime: string | null;
  testId?: string;
}

interface BannerStyle {
  icon: LucideIcon;
  iconClassName: string;
  containerClassName: string;
  spin?: boolean;
}

const BANNER_STYLES: Record<SystemStatus, BannerStyle> = {
  [SystemStatus.CHECKING]: {
    icon: Loader2,
    spin: true,
    iconClassName: "text-muted-foreground",
    containerClassName: "bg-muted/50 border-border text-muted-foreground",
  },
  [SystemStatus.OPERATIONAL]: {
    icon: CheckCircle2,
    iconClassName: "text-success",
    containerClassName: "bg-success/10 border-success/30 text-foreground",
  },
  [SystemStatus.DEGRADED]: {
    icon: AlertTriangle,
    iconClassName: "text-warning",
    containerClassName: "bg-warning/10 border-warning/30 text-foreground",
  },
  [SystemStatus.DISRUPTED]: {
    icon: XCircle,
    iconClassName: "text-destructive",
    containerClassName: "bg-destructive/10 border-destructive/30 text-foreground",
  },
};

function subline(status: SystemStatus, datetime: string | null): string | null {
  if (status === SystemStatus.DISRUPTED) {
    return "We're having trouble reaching this service. Please try again shortly.";
  }
  if (datetime) {
    return `as of ${dateTimeProvider.formatIsoToLocalDateTime(datetime)}`;
  }
  return null;
}

export const SystemStatusBanner: React.FC<SystemStatusBannerProps> = ({
  status,
  datetime,
  testId,
}) => {
  const style = BANNER_STYLES[status];
  const Icon = style.icon;
  const note = subline(status, datetime);

  return (
    <div
      role="status"
      aria-live="polite"
      aria-busy={status === SystemStatus.CHECKING}
      data-testid={testId}
      className={cn(
        "system-status-banner flex items-center gap-4 rounded-lg border p-6",
        style.containerClassName,
      )}
    >
      <Icon
        className={cn(
          "system-status-banner__icon size-8 shrink-0",
          style.iconClassName,
          style.spin && "animate-spin",
        )}
        aria-hidden="true"
      />
      <div className="system-status-banner__text">
        <p className="system-status-banner__headline text-base font-semibold">
          {systemHeadline(status)}
        </p>
        {note ? (
          <p className="system-status-banner__subline text-muted-foreground text-sm">{note}</p>
        ) : null}
      </div>
    </div>
  );
};
```

- [ ] **Step 4: Run the test to verify it passes**

Run: `make pwa.test.unit c='tests/app/backoffice/health/SystemStatusBanner.test.tsx'`
Expected: PASS (4 tests green).

- [ ] **Step 5: Commit**

```bash
git add pwa/src/app/backoffice/health/_components/SystemStatusBanner.tsx pwa/tests/app/backoffice/health/SystemStatusBanner.test.tsx
git commit -m "feat(backoffice): add token-styled SystemStatusBanner for health page"
```

---

### Task 3: `HealthComponentRow` (backoffice, uses `<StatusBadge>`)

**Files:**
- Create: `pwa/src/app/backoffice/health/_components/HealthComponentRow.tsx`
- Test: `pwa/tests/app/backoffice/health/HealthComponentRow.test.tsx`

- [ ] **Step 1: Write the failing test**

Create `pwa/tests/app/backoffice/health/HealthComponentRow.test.tsx`:

```tsx
import { describe, expect, it } from "vitest";
import { render, screen } from "@testing-library/react";
import { HealthComponentRow } from "@/app/backoffice/health/_components/HealthComponentRow";
import { SystemStatus } from "@/lib/systemStatus";

describe("HealthComponentRow", () => {
  it("renders the component name and its operational label", () => {
    render(
      <HealthComponentRow name="BackOffice API" status={SystemStatus.OPERATIONAL} testId="x-row" />,
    );
    const row = screen.getByTestId("x-row");
    expect(row).toHaveTextContent("BackOffice API");
    expect(row).toHaveTextContent("Operational");
  });

  it("shows the disrupted label when the component is down", () => {
    render(
      <HealthComponentRow name="BackOffice API" status={SystemStatus.DISRUPTED} testId="x-row" />,
    );
    expect(screen.getByTestId("x-row")).toHaveTextContent("Disrupted");
  });
});
```

- [ ] **Step 2: Run the test to verify it fails**

Run: `make pwa.test.unit c='tests/app/backoffice/health/HealthComponentRow.test.tsx'`
Expected: FAIL — cannot resolve `@/app/backoffice/health/_components/HealthComponentRow`.

- [ ] **Step 3: Write the implementation**

Create `pwa/src/app/backoffice/health/_components/HealthComponentRow.tsx`:

```tsx
import React from "react";
import { StatusBadge } from "@/components/erpify";
import { SystemStatus, componentStatusLabel } from "@/lib/systemStatus";

type StatusBadgeVariant = "success" | "warning" | "danger" | "info" | "neutral";

const BADGE_VARIANT: Record<SystemStatus, StatusBadgeVariant> = {
  [SystemStatus.CHECKING]: "neutral",
  [SystemStatus.OPERATIONAL]: "success",
  [SystemStatus.DEGRADED]: "warning",
  [SystemStatus.DISRUPTED]: "danger",
};

interface HealthComponentRowProps {
  name: string;
  status: SystemStatus;
  testId?: string;
}

export const HealthComponentRow: React.FC<HealthComponentRowProps> = ({ name, status, testId }) => {
  return (
    <div
      data-testid={testId}
      className="health-component-row border-border flex items-center justify-between border-b py-4 last:border-b-0"
    >
      <span className="health-component-row__name text-foreground font-medium">{name}</span>
      <StatusBadge variant={BADGE_VARIANT[status]} label={componentStatusLabel(status)} />
    </div>
  );
};
```

- [ ] **Step 4: Run the test to verify it passes**

Run: `make pwa.test.unit c='tests/app/backoffice/health/HealthComponentRow.test.tsx'`
Expected: PASS (2 tests green).

- [ ] **Step 5: Commit**

```bash
git add pwa/src/app/backoffice/health/_components/HealthComponentRow.tsx pwa/tests/app/backoffice/health/HealthComponentRow.test.tsx
git commit -m "feat(backoffice): add HealthComponentRow using the StatusBadge primitive"
```

---

### Task 4: Redesign the `/backoffice/health` page

**Files:**
- Modify (overwrite): `pwa/src/app/backoffice/health/page.tsx`

- [ ] **Step 1: Overwrite the page**

Replace `pwa/src/app/backoffice/health/page.tsx` with exactly this:

```tsx
"use client";

import { useCallback, useEffect, useState } from "react";
import { RefreshCw } from "lucide-react";
import { container } from "@/context/shared/infrastructure/DependencyInjection/Container";
import { CheckHealth } from "@/context/backoffice/health/application/CheckHealth";
import type { HealthCheck } from "@/context/backoffice/health/domain/HealthCheck";
import type { ProblemDetails } from "@/context/shared/domain/ProblemDetails";
import { HttpError } from "@/context/shared/infrastructure/HttpClient/HttpError";
import { apiScope } from "@/context/shared/domain/Observability/TelemetryScope";
import { telemetry } from "@/context/shared/infrastructure/Observability";
import { deriveSystemStatus } from "@/lib/systemStatus";
import { uuidV7 } from "@/lib/uuidV7";
import { Button } from "@/components/ui/button";
import { ProblemDisplay } from "@/components/erpify";
import { SystemStatusBanner } from "./_components/SystemStatusBanner";
import { HealthComponentRow } from "./_components/HealthComponentRow";

const MONITORED_COMPONENTS = [{ key: "backoffice", name: "BackOffice API" }] as const;

function transportFailureProblem(detail: string): ProblemDetails {
  return {
    type: "health-check-failed",
    title: "Health check failed",
    status: 0,
    detail,
    instance: uuidV7(),
    "correlation-id": uuidV7(),
  };
}

export default function HealthPage() {
  const [result, setResult] = useState<HealthCheck | null>(null);
  const [checking, setChecking] = useState(true);
  const [problem, setProblem] = useState<ProblemDetails | null>(null);

  const runCheck = useCallback(async () => {
    setChecking(true);
    setProblem(null);
    try {
      const useCase = container.get<CheckHealth>("BackOfficeCheckHealth");
      setResult(await useCase.run());
    } catch (err) {
      const detail = err instanceof Error ? err.message : "Unknown error";
      telemetry.warn("BackOffice health check failed", {
        scope: apiScope("backoffice-health"),
        cause: err,
      });
      setResult(null);
      setProblem(err instanceof HttpError ? err.problem : transportFailureProblem(detail));
    } finally {
      setChecking(false);
    }
  }, []);

  useEffect(() => {
    // runCheck resets checking/problem before its first await; that initial
    // setState is intentional (it also drives the manual Refresh path) and runs
    // through a stable callback, so the cascading-render warning does not apply.
    // eslint-disable-next-line react-hooks/set-state-in-effect
    runCheck();
  }, [runCheck]);

  const view = deriveSystemStatus({ checking, failed: problem !== null, result });

  return (
    <div className="health-page space-y-8">
      <header className="health-page__header flex flex-col gap-1">
        <h1 className="text-foreground text-2xl font-semibold tracking-tight">System Health</h1>
        <p className="text-muted-foreground text-sm">
          Live availability of your BackOffice API services.
        </p>
      </header>

      <section className="health-page__status bg-card border-border space-y-6 rounded-lg border p-6">
        <SystemStatusBanner
          status={view.status}
          datetime={view.datetime}
          testId="backoffice-health__banner"
        />

        <div className="health-page__components">
          <h2 className="text-muted-foreground mb-2 text-xs font-semibold tracking-wide uppercase">
            Components
          </h2>
          {MONITORED_COMPONENTS.map((component) => (
            <HealthComponentRow
              key={component.key}
              name={component.name}
              status={view.status}
              testId={`backoffice-health__component-${component.key}`}
            />
          ))}
        </div>

        {problem ? <ProblemDisplay variant="inline" problem={problem} /> : null}

        <div className="health-page__actions flex justify-end">
          <Button
            variant="ghost"
            onClick={() => {
              runCheck();
            }}
            disabled={checking}
            title="Re-check service status"
            aria-label="Refresh status"
            data-testid="backoffice-health__refresh"
          >
            <RefreshCw className={`size-4 ${checking ? "animate-spin" : ""}`} aria-hidden="true" />
            {checking ? "Checking…" : "Refresh"}
          </Button>
        </div>
      </section>
    </div>
  );
}
```

- [ ] **Step 2: Confirm the old testid is gone**

Run: `grep -rn "backoffice-health-status" pwa/src || echo "CLEAN: old testid removed from src"`
Expected: prints `CLEAN: old testid removed from src`.

- [ ] **Step 3: Verify lint + format**

Run: `make pwa.quality`
Expected: PASS (no unused-import errors for the removed `AsyncBoundary` / `ApiStatus` / `ViewStatus` / `ShieldCheck` / `Activity` / `dateTimeProvider`).

- [ ] **Step 4: Smoke-check the page renders (stack is up)**

Run: `curl -ks -H 'Accept: text/html' https://localhost/backoffice/health | grep -o 'System Health' | head -1`
Expected: prints `System Health`. (Retry once after ~5s if empty — first compile of the route.)

- [ ] **Step 5: Commit**

```bash
git add pwa/src/app/backoffice/health/page.tsx
git commit -m "feat(backoffice): redesign health page as auto-loaded status view"
```

---

### Task 5: Update the BackOffice health e2e

**Files:**
- Modify: `pwa/tests/e2e/helpers/health-assertions.ts`
- Modify: `pwa/tests/e2e/backoffice/dashboard.spec.ts`

- [ ] **Step 1: Rewrite `expectBackOfficeHealthOk`**

In `pwa/tests/e2e/helpers/health-assertions.ts`, replace the `expectBackOfficeHealthOk` function (keep `expectStatusPageOperational` and the imports untouched) with:

```ts
export async function expectBackOfficeHealthOk(page: Page): Promise<void> {
  const banner = page.getByTestId("backoffice-health__banner");
  await expect(banner).toBeVisible({ timeout: HEALTH_CHECK_TIMEOUT_MS });
  await expect(banner).toContainText(/All Systems Operational/i);

  const component = page.getByTestId("backoffice-health__component-backoffice");
  await expect(component).toContainText(/BackOffice API/i);
  await expect(component).toContainText(/Operational/i);
}
```

- [ ] **Step 2: Drop the manual button click in the dashboard spec**

In `pwa/tests/e2e/backoffice/dashboard.spec.ts`, the page now auto-loads, so remove the `Run Health Check` button click in BOTH the desktop and mobile health tests.

Desktop test — change:
```ts
    test("reaches health check via Administration sidebar", async ({ page }) => {
      await navigateToHealthViaSidebarDesktop(page);
      await expect(page).toHaveURL("/backoffice/health");
      await page.getByRole("button", { name: "Run Health Check" }).click();
      await expectBackOfficeHealthOk(page);
    });
```
to:
```ts
    test("reaches health check via Administration sidebar", async ({ page }) => {
      await navigateToHealthViaSidebarDesktop(page);
      await expect(page).toHaveURL("/backoffice/health");
      await expectBackOfficeHealthOk(page);
    });
```

Mobile test — change:
```ts
    test("reaches health check via mobile menu", async ({ page }) => {
      await navigateToHealthViaSidebarMobile(page);
      await expect(page).toHaveURL("/backoffice/health");
      await page.getByRole("button", { name: "Run Health Check" }).click();
      await expectBackOfficeHealthOk(page);
    });
```
to:
```ts
    test("reaches health check via mobile menu", async ({ page }) => {
      await navigateToHealthViaSidebarMobile(page);
      await expect(page).toHaveURL("/backoffice/health");
      await expectBackOfficeHealthOk(page);
    });
```

- [ ] **Step 3: Run the BackOffice dashboard e2e (stack must be up)**

Run: `PLAYWRIGHT_HOST_PLATFORM_OVERRIDE=ubuntu24.04-x64 make pwa.test.e2e c='tests/e2e/backoffice/dashboard.spec.ts'`
Expected: PASS — all dashboard tests green (desktop + mobile health navigation now assert the auto-loaded operational banner). If `expectBackOfficeHealthOk` times out, confirm `curl -ks https://localhost/api/v1/backoffice/health` returns `{"data":{"status":"ok",...}}`. Do NOT weaken assertions; if a real bug surfaces, report BLOCKED.

- [ ] **Step 4: Commit**

```bash
git add pwa/tests/e2e/helpers/health-assertions.ts pwa/tests/e2e/backoffice/dashboard.spec.ts
git commit -m "test(backoffice): assert auto-loaded health status banner in dashboard e2e"
```

---

### Task 6: Docs, full quality gates, security review

**Files:**
- Modify: `docs/architecture-pwa.md`

- [ ] **Step 1: Note the redesign in the PWA architecture doc**

Open `docs/architecture-pwa.md`, find the section describing the `app/backoffice/health/` route (or the App Router route inventory — search for `backoffice/health` or `status`). Add/adjust an entry conveying:

> `app/backoffice/health/` — internal admin health page, redesigned to the status-page style (aggregate banner + per-component row, auto-loaded on mount with a Refresh) in the backoffice design language. Reuses the shared `@/lib/systemStatus` model (also used by the public `app/status/` page) and keeps the technical `ProblemDetails` detail on failure for admins. Failures report via telemetry with `apiScope("backoffice-health")`.

Match the surrounding format. MARKDOWN LINK RULE (repo linter): only link concrete files; never directory-href links (`[app/backoffice/health/](app/backoffice/health/)`) and never glob links — use inline code for directory paths.

- [ ] **Step 2: Full PWA quality gates**

Run: `make pwa.quality`
Expected: PASS.
Run: `make pwa.test.unit`
Expected: PASS — includes the moved `tests/lib/systemStatus.test.ts`, the two new backoffice component tests, and the `data-testid` uniqueness guard. (No file should still reference `@/app/status/_components/systemStatus` or `backoffice-health-status`.)

- [ ] **Step 3: Security self-review (report findings for the PR description)**

Confirm and record yes/no with one-line justification:
- `/backoffice/health` is an internal admin diagnostic page; showing `ProblemDetails` / correlation id there is intentional, not a public leak. Auth posture unchanged.
- Failure detail routed to telemetry via `apiScope("backoffice-health")` — ops-only, silent in prod (`telemetry.warn` with the `err` in `cause`, never rendered).
- No `dangerouslySetInnerHTML` / `innerHTML` / `eval`; design tokens only; no dynamic/user-influenced URLs.
- No new dependencies; no CSP/header changes; same same-origin `GET /api/v1/backoffice/health`.

- [ ] **Step 4: Commit**

```bash
git add docs/architecture-pwa.md
git commit -m "docs(backoffice): document the health page status redesign"
```

---

## Done criteria

- `/backoffice/health` auto-runs the BackOffice health check on load and shows "All Systems Operational" + a "BackOffice API → Operational" row when healthy; a destructive-toned banner + `<ProblemDisplay>` detail when the API is unreachable.
- A manual "Refresh" re-checks without reload.
- The public `/status` page still works (imports the model from `@/lib/systemStatus`); its unit + e2e tests pass.
- `make pwa.quality` and `make pwa.test.unit` pass; the BackOffice dashboard e2e passes against the live stack.
- Non-goals stay out: no polling/realtime, no history/incidents, no FrontOffice on this page, no auth changes.
