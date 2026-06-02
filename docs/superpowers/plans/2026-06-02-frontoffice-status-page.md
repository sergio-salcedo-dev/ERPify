# FrontOffice Public Status Page — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Move the FrontOffice health check off the landing page into a dedicated public `/status` page styled like Atlassian Statuspage, which auto-runs the check on load and shows an aggregate banner plus a per-component row.

**Architecture:** A `"use client"` route under `pwa/src/app/status/` reuses the existing `FrontOfficeCheckHealth` use case (no backend changes). Presentation lives in route-local components (`app/status/_components/`) written in the **marketing** design language (raw Tailwind palette, like the landing — never the backoffice `@/components/erpify` system). A pure `systemStatus.ts` module maps the health result/error to a UI status enum and is unit-tested in isolation; the page is thin glue covered by e2e.

**Tech Stack:** Next.js 16 App Router · TypeScript (strict) · Tailwind 4 · shadcn `Button` · Inversify · lucide-react · Vitest + Testing Library · Playwright.

---

## Context the engineer needs

- **Two design languages, do not cross them.** `pwa/CLAUDE.md` mandates: the public/marketing surface (landing, this page) uses raw Tailwind palette (`slate`/`emerald`/`amber`/`rose`) and components under `app/_components/` or a route's own `_components/`. The backoffice uses token-driven shadcn + `@/components/erpify`. This page is public → marketing language. You MAY use the shadcn `Button` (`@/components/ui/button`) — the landing's `FeatureCard` and `Navbar` already do.
- **Existing health use case** (reuse, do not rebuild): `container.get<CheckHealth>("FrontOfficeCheckHealth").run()` returns a `HealthCheck { status: string; service: string; datetime: string }` (`pwa/src/context/frontoffice/health/...`). It calls `GET /api/v1/health` and throws `HttpError` (transport/HTTP failure).
- **Run from repo root.** Make targets exec inside the container. After PWA edits, `make pwa.quality` is REQUIRED (ESLint + Prettier).
- **E2E hits the live stack** at `https://localhost` — bring it up with `make app.dev` first. On this host (Ubuntu 26.04) Playwright needs `PLAYWRIGHT_HOST_PLATFORM_OVERRIDE=ubuntu24.04-x64` prefixed on install/run commands (do not bake it into shared make targets).
- **`data-testid` uniqueness** is enforced by `tests/data-testid-uniqueness.test.ts` over `src/`. Every literal added here is unique; the old `frontoffice-health-status` literal is removed from the landing.
- **Spec:** `docs/superpowers/specs/2026-06-02-frontoffice-status-page-design.md`.

## File Structure

**Create:**
- `pwa/src/app/status/page.tsx` — public `/status` route (`"use client"`); fetches on mount, renders banner + components + refresh.
- `pwa/src/app/status/_components/systemStatus.ts` — pure status model: `SystemStatus` enum, `deriveSystemStatus`, `systemHeadline`, `componentStatusLabel`.
- `pwa/src/app/status/_components/StatusBanner.tsx` — aggregate banner (marketing language).
- `pwa/src/app/status/_components/ComponentStatusRow.tsx` — one monitored-component row.
- `pwa/tests/app/status/systemStatus.test.ts` — unit tests for the pure module.
- `pwa/tests/app/status/StatusBanner.test.tsx` — render tests.
- `pwa/tests/app/status/ComponentStatusRow.test.tsx` — render tests.
- `pwa/tests/e2e/frontoffice/status.spec.ts` — e2e auto-load + refresh.

**Modify:**
- `pwa/src/context/shared/domain/types/routes.ts` — add `STATUS: "/status"`.
- `pwa/src/app/_components/Navbar.tsx` — add "Status" link (desktop + mobile).
- `pwa/src/app/_components/Footer.tsx` — add "Status" link.
- `pwa/src/app/page.tsx` — remove the FrontOffice health card + state; center the single card.
- `pwa/tests/e2e/helpers/health-assertions.ts` — replace `expectFrontOfficeHealthOk` with `expectStatusPageOperational`; keep `expectBackOfficeHealthOk`.
- `pwa/tests/e2e/frontoffice/landing.spec.ts` — drop the health-button test; add a Status-link navigation test.
- `docs/architecture-pwa.md`, `docs/source-tree-analysis.md`, `docs/claude-code-quickref.md` — register the new public route.

---

### Task 1: Add the `STATUS` route constant

**Files:**
- Modify: `pwa/src/context/shared/domain/types/routes.ts`

- [ ] **Step 1: Add the constant**

In `pwa/src/context/shared/domain/types/routes.ts`, add the `STATUS` entry immediately after the `BACKOFFICE` entry:

```ts
  /** Authenticated BackOffice root — every `/backoffice/*` path lives under this prefix. */
  BACKOFFICE: "/backoffice",
  /** Public service status page (Atlassian-style). Unauthenticated, like {@link HOME}. */
  STATUS: "/status",
```

- [ ] **Step 2: Verify it type-checks via lint**

Run: `make pwa.quality`
Expected: PASS (no ESLint/Prettier errors). If Prettier reports formatting, run `make pwa.format` and re-check.

- [ ] **Step 3: Commit**

```bash
git add pwa/src/context/shared/domain/types/routes.ts
git commit -m "feat(frontoffice): add STATUS route constant for public status page"
```

---

### Task 2: Pure status model (`systemStatus.ts`)

**Files:**
- Create: `pwa/src/app/status/_components/systemStatus.ts`
- Test: `pwa/tests/app/status/systemStatus.test.ts`

- [ ] **Step 1: Write the failing test**

Create `pwa/tests/app/status/systemStatus.test.ts`:

```ts
import { describe, expect, it } from "vitest";
import { HealthCheck } from "@/context/frontoffice/health/domain/HealthCheck";
import {
  SystemStatus,
  componentStatusLabel,
  deriveSystemStatus,
  systemHeadline,
} from "@/app/status/_components/systemStatus";

const okResult = new HealthCheck("ok", "Front office", "2026-06-02T10:00:00+02:00");

describe("deriveSystemStatus", () => {
  it("reports CHECKING while a check is in flight", () => {
    expect(deriveSystemStatus({ checking: true, failed: false, result: null })).toEqual({
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
    const degraded = new HealthCheck("degraded", "Front office", "2026-06-02T10:00:00+02:00");
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

- [ ] **Step 2: Run the test to verify it fails**

Run: `make pwa.test.unit c='tests/app/status/systemStatus.test.ts'`
Expected: FAIL — cannot resolve `@/app/status/_components/systemStatus` (module not created yet).

- [ ] **Step 3: Write the implementation**

Create `pwa/src/app/status/_components/systemStatus.ts`:

```ts
import type { HealthCheck } from "@/context/frontoffice/health/domain/HealthCheck";

/** Aggregate UI status for the public status page (Atlassian-style). */
export const SystemStatus = {
  CHECKING: "checking",
  OPERATIONAL: "operational",
  DEGRADED: "degraded",
  DISRUPTED: "disrupted",
} as const;
export type SystemStatus = (typeof SystemStatus)[keyof typeof SystemStatus];

export interface SystemStatusInput {
  /** A health check is in flight. */
  checking: boolean;
  /** The last check threw (transport / HTTP error). */
  failed: boolean;
  /** The last successful health check, or null when none yet / failed. */
  result: HealthCheck | null;
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

- [ ] **Step 4: Run the test to verify it passes**

Run: `make pwa.test.unit c='tests/app/status/systemStatus.test.ts'`
Expected: PASS (all 3 describe blocks green).

- [ ] **Step 5: Commit**

```bash
git add pwa/src/app/status/_components/systemStatus.ts pwa/tests/app/status/systemStatus.test.ts
git commit -m "feat(frontoffice): add pure status model for the public status page"
```

---

### Task 3: `StatusBanner` component

**Files:**
- Create: `pwa/src/app/status/_components/StatusBanner.tsx`
- Test: `pwa/tests/app/status/StatusBanner.test.tsx`

- [ ] **Step 1: Write the failing test**

Create `pwa/tests/app/status/StatusBanner.test.tsx`:

```tsx
import { describe, expect, it } from "vitest";
import { render, screen } from "@testing-library/react";
import { StatusBanner } from "@/app/status/_components/StatusBanner";
import { SystemStatus } from "@/app/status/_components/systemStatus";

describe("StatusBanner", () => {
  it("shows the operational headline and an 'as of' subline with the datetime", () => {
    render(
      <StatusBanner
        status={SystemStatus.OPERATIONAL}
        datetime="2026-06-02T10:00:00+02:00"
        testId="x-banner"
      />,
    );
    const banner = screen.getByTestId("x-banner");
    expect(banner).toHaveTextContent("All Systems Operational");
    expect(banner).toHaveTextContent(/as of/i);
  });

  it("exposes role=status with aria-live for assistive tech", () => {
    render(<StatusBanner status={SystemStatus.CHECKING} datetime={null} testId="x-banner" />);
    const banner = screen.getByTestId("x-banner");
    expect(banner).toHaveAttribute("role", "status");
    expect(banner).toHaveAttribute("aria-live", "polite");
  });

  it("shows a friendly disruption message and no datetime when disrupted", () => {
    render(<StatusBanner status={SystemStatus.DISRUPTED} datetime={null} testId="x-banner" />);
    const banner = screen.getByTestId("x-banner");
    expect(banner).toHaveTextContent("Service Disruption");
    expect(banner).toHaveTextContent(/trouble reaching this service/i);
    expect(banner).not.toHaveTextContent(/as of/i);
  });

  it("renders an aria-hidden icon", () => {
    const { container } = render(
      <StatusBanner status={SystemStatus.OPERATIONAL} datetime={null} testId="x-banner" />,
    );
    const svg = container.querySelector("svg");
    expect(svg).toHaveAttribute("aria-hidden", "true");
  });
});
```

- [ ] **Step 2: Run the test to verify it fails**

Run: `make pwa.test.unit c='tests/app/status/StatusBanner.test.tsx'`
Expected: FAIL — cannot resolve `@/app/status/_components/StatusBanner`.

- [ ] **Step 3: Write the implementation**

Create `pwa/src/app/status/_components/StatusBanner.tsx`:

```tsx
import React from "react";
import { AlertTriangle, CheckCircle2, Loader2, XCircle, type LucideIcon } from "lucide-react";
import { dateTimeProvider } from "@/context/shared/infrastructure/DateTimeProvider";
import { cn } from "@/lib/utils";
import { SystemStatus, systemHeadline } from "./systemStatus";

interface StatusBannerProps {
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
    iconClassName: "text-slate-500",
    containerClassName: "bg-slate-50 border-slate-200 text-slate-700",
  },
  [SystemStatus.OPERATIONAL]: {
    icon: CheckCircle2,
    iconClassName: "text-emerald-600",
    containerClassName: "bg-emerald-50 border-emerald-200 text-emerald-800",
  },
  [SystemStatus.DEGRADED]: {
    icon: AlertTriangle,
    iconClassName: "text-amber-600",
    containerClassName: "bg-amber-50 border-amber-200 text-amber-800",
  },
  [SystemStatus.DISRUPTED]: {
    icon: XCircle,
    iconClassName: "text-rose-600",
    containerClassName: "bg-rose-50 border-rose-200 text-rose-800",
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

export const StatusBanner: React.FC<StatusBannerProps> = ({ status, datetime, testId }) => {
  const style = BANNER_STYLES[status];
  const Icon = style.icon;
  const note = subline(status, datetime);

  return (
    <div
      role="status"
      aria-live="polite"
      data-testid={testId}
      className={cn(
        "status-banner flex items-center gap-4 rounded-2xl border p-6",
        style.containerClassName,
      )}
    >
      <Icon
        className={cn(
          "status-banner__icon size-8 shrink-0",
          style.iconClassName,
          style.spin && "animate-spin",
        )}
        aria-hidden="true"
      />
      <div className="status-banner__text">
        <p className="status-banner__headline text-lg font-semibold">{systemHeadline(status)}</p>
        {note ? <p className="status-banner__subline text-sm opacity-80">{note}</p> : null}
      </div>
    </div>
  );
};
```

- [ ] **Step 4: Run the test to verify it passes**

Run: `make pwa.test.unit c='tests/app/status/StatusBanner.test.tsx'`
Expected: PASS (4 tests green).

- [ ] **Step 5: Commit**

```bash
git add pwa/src/app/status/_components/StatusBanner.tsx pwa/tests/app/status/StatusBanner.test.tsx
git commit -m "feat(frontoffice): add StatusBanner for the public status page"
```

---

### Task 4: `ComponentStatusRow` component

**Files:**
- Create: `pwa/src/app/status/_components/ComponentStatusRow.tsx`
- Test: `pwa/tests/app/status/ComponentStatusRow.test.tsx`

- [ ] **Step 1: Write the failing test**

Create `pwa/tests/app/status/ComponentStatusRow.test.tsx`:

```tsx
import { describe, expect, it } from "vitest";
import { render, screen } from "@testing-library/react";
import { ComponentStatusRow } from "@/app/status/_components/ComponentStatusRow";
import { SystemStatus } from "@/app/status/_components/systemStatus";

describe("ComponentStatusRow", () => {
  it("renders the component name and its operational label", () => {
    render(
      <ComponentStatusRow
        name="FrontOffice API"
        status={SystemStatus.OPERATIONAL}
        testId="x-row"
      />,
    );
    const row = screen.getByTestId("x-row");
    expect(row).toHaveTextContent("FrontOffice API");
    expect(row).toHaveTextContent("Operational");
  });

  it("shows the disrupted label when the component is down", () => {
    render(
      <ComponentStatusRow name="FrontOffice API" status={SystemStatus.DISRUPTED} testId="x-row" />,
    );
    expect(screen.getByTestId("x-row")).toHaveTextContent("Disrupted");
  });

  it("renders the status dot as decorative (aria-hidden)", () => {
    const { container } = render(
      <ComponentStatusRow
        name="FrontOffice API"
        status={SystemStatus.OPERATIONAL}
        testId="x-row"
      />,
    );
    const dot = container.querySelector(".component-status-row__dot");
    expect(dot).toHaveAttribute("aria-hidden", "true");
  });
});
```

- [ ] **Step 2: Run the test to verify it fails**

Run: `make pwa.test.unit c='tests/app/status/ComponentStatusRow.test.tsx'`
Expected: FAIL — cannot resolve `@/app/status/_components/ComponentStatusRow`.

- [ ] **Step 3: Write the implementation**

Create `pwa/src/app/status/_components/ComponentStatusRow.tsx`:

```tsx
import React from "react";
import { cn } from "@/lib/utils";
import { SystemStatus, componentStatusLabel } from "./systemStatus";

interface ComponentStatusRowProps {
  name: string;
  status: SystemStatus;
  testId?: string;
}

const DOT_CLASSNAME: Record<SystemStatus, string> = {
  [SystemStatus.CHECKING]: "bg-slate-400",
  [SystemStatus.OPERATIONAL]: "bg-emerald-500",
  [SystemStatus.DEGRADED]: "bg-amber-500",
  [SystemStatus.DISRUPTED]: "bg-rose-500",
};

const LABEL_CLASSNAME: Record<SystemStatus, string> = {
  [SystemStatus.CHECKING]: "text-slate-500",
  [SystemStatus.OPERATIONAL]: "text-emerald-700",
  [SystemStatus.DEGRADED]: "text-amber-700",
  [SystemStatus.DISRUPTED]: "text-rose-700",
};

export const ComponentStatusRow: React.FC<ComponentStatusRowProps> = ({ name, status, testId }) => {
  return (
    <div
      data-testid={testId}
      className="component-status-row flex items-center justify-between border-b border-slate-100 py-4 last:border-b-0"
    >
      <span className="component-status-row__name font-medium text-slate-700">{name}</span>
      <span
        className={cn(
          "component-status-row__status flex items-center gap-2 text-sm font-medium",
          LABEL_CLASSNAME[status],
        )}
      >
        <span
          className={cn("component-status-row__dot size-2 rounded-full", DOT_CLASSNAME[status])}
          aria-hidden="true"
        />
        {componentStatusLabel(status)}
      </span>
    </div>
  );
};
```

- [ ] **Step 4: Run the test to verify it passes**

Run: `make pwa.test.unit c='tests/app/status/ComponentStatusRow.test.tsx'`
Expected: PASS (3 tests green).

- [ ] **Step 5: Commit**

```bash
git add pwa/src/app/status/_components/ComponentStatusRow.tsx pwa/tests/app/status/ComponentStatusRow.test.tsx
git commit -m "feat(frontoffice): add ComponentStatusRow for the public status page"
```

---

### Task 5: The `/status` page

**Files:**
- Create: `pwa/src/app/status/page.tsx`

- [ ] **Step 1: Write the page**

Create `pwa/src/app/status/page.tsx`:

```tsx
"use client";

import { useCallback, useEffect, useState } from "react";
import { useRouter } from "next/navigation";
import { RefreshCw } from "lucide-react";
import { container } from "@/context/shared/infrastructure/DependencyInjection/Container";
import { CheckHealth } from "@/context/frontoffice/health/application/CheckHealth";
import type { HealthCheck } from "@/context/frontoffice/health/domain/HealthCheck";
import { Routes } from "@/context/shared/domain/types/routes";
import { Navbar } from "@/app/_components/Navbar";
import { Footer } from "@/app/_components/Footer";
import { Button } from "@/components/ui/button";
import { StatusBanner } from "./_components/StatusBanner";
import { ComponentStatusRow } from "./_components/ComponentStatusRow";
import { deriveSystemStatus } from "./_components/systemStatus";

const MONITORED_COMPONENTS = [{ key: "frontoffice", name: "FrontOffice API" }] as const;

export default function StatusPage() {
  const router = useRouter();
  const [result, setResult] = useState<HealthCheck | null>(null);
  const [checking, setChecking] = useState(true);
  const [failed, setFailed] = useState(false);

  const runCheck = useCallback(async () => {
    setChecking(true);
    setFailed(false);
    try {
      const useCase = container.get<CheckHealth>("FrontOfficeCheckHealth");
      setResult(await useCase.run());
    } catch {
      setResult(null);
      setFailed(true);
    } finally {
      setChecking(false);
    }
  }, []);

  useEffect(() => {
    void runCheck();
  }, [runCheck]);

  const view = deriveSystemStatus({ checking, failed, result });

  return (
    <div className="status-page flex min-h-screen flex-col bg-slate-50 font-sans">
      <Navbar onGetStarted={() => router.push(Routes.BACKOFFICE)} />

      <main className="status-page__main flex-grow">
        <section className="status-page__content mx-auto max-w-3xl px-4 py-12 sm:px-6 md:py-20 lg:px-8">
          <header className="status-page__header mb-8 text-center">
            <h1 className="status-page__title text-3xl font-extrabold tracking-tight text-slate-900 md:text-4xl">
              System Status
            </h1>
            <p className="status-page__subtitle mt-3 text-slate-600">
              Live availability of Erpify services.
            </p>
          </header>

          <StatusBanner
            status={view.status}
            datetime={view.datetime}
            testId="status-page__banner"
          />

          <div className="status-page__components mt-8 rounded-2xl border border-slate-200 bg-white p-6 shadow-sm">
            <h2 className="status-page__components-title mb-2 text-sm font-semibold tracking-wide text-slate-500 uppercase">
              Components
            </h2>
            {MONITORED_COMPONENTS.map((component) => (
              <ComponentStatusRow
                key={component.key}
                name={component.name}
                status={view.status}
                testId={`status-page__component-${component.key}`}
              />
            ))}
          </div>

          <div className="status-page__actions mt-6 flex justify-center">
            <Button
              variant="ghost"
              size="lg"
              onClick={() => void runCheck()}
              disabled={checking}
              title="Re-check service status"
              aria-label="Refresh status"
              data-testid="status-page__refresh"
              className="status-page__refresh"
            >
              <RefreshCw
                className={`size-4 ${checking ? "animate-spin" : ""}`}
                aria-hidden="true"
              />
              {checking ? "Checking…" : "Refresh"}
            </Button>
          </div>
        </section>
      </main>

      <Footer />
    </div>
  );
}
```

- [ ] **Step 2: Verify lint + format**

Run: `make pwa.quality`
Expected: PASS. If Prettier complains, run `make pwa.format` then re-run `make pwa.quality`.

- [ ] **Step 3: Smoke-check the page renders (stack must be up)**

If the stack isn't running: `make app.dev` (wait for it). Then:
Run: `curl -ks -H 'Accept: text/html' https://localhost/status | grep -o 'System Status' | head -1`
Expected: prints `System Status` (the page server-renders the heading). A `404` body instead means the route file path is wrong — confirm it's `pwa/src/app/status/page.tsx`.

- [ ] **Step 4: Commit**

```bash
git add pwa/src/app/status/page.tsx
git commit -m "feat(frontoffice): add public /status page with auto-loaded health check"
```

---

### Task 6: Surface `/status` in the navbar and footer

**Files:**
- Modify: `pwa/src/app/_components/Navbar.tsx`
- Modify: `pwa/src/app/_components/Footer.tsx`

- [ ] **Step 1: Add the desktop "Status" link in the navbar**

In `pwa/src/app/_components/Navbar.tsx`, inside the desktop menu (`<div className="navbar__menu ...">`), insert this `<Link>` immediately after the `{navLinks.map(...)}` block and before the `{showDevTools ? (` block:

```tsx
            <Link
              href={Routes.STATUS}
              className="navbar__link text-slate-600 hover:text-blue-600 font-medium transition-colors"
              data-testid="navbar__link-status"
            >
              Status
            </Link>
```

(`Link` and `Routes` are already imported in this file.)

- [ ] **Step 2: Add the mobile "Status" link in the navbar**

In the same file, inside the mobile menu (`<div className="navbar__mobile-menu ...">`), insert this `<Link>` immediately after the mobile `{navLinks.map(...)}` block and before the mobile `{showDevTools ? (` block:

```tsx
          <Link
            href={Routes.STATUS}
            className="navbar__link block text-slate-600 font-medium"
            data-testid="navbar__link-status--mobile"
          >
            Status
          </Link>
```

- [ ] **Step 3: Add the "Status" link in the footer**

In `pwa/src/app/_components/Footer.tsx`, add these imports at the top (after the existing `import { Logo } ...` line):

```tsx
import Link from "next/link";
import { Routes } from "@/context/shared/domain/types/routes";
```

Then inside `<div className="footer__links ...">`, add this `<Link>` immediately after the `{footerLinks.map(...)}` block:

```tsx
            <Link
              href={Routes.STATUS}
              className="footer__link hover:text-primary"
              data-testid="footer__link-status"
            >
              Status
            </Link>
```

- [ ] **Step 4: Verify lint + format and testid uniqueness**

Run: `make pwa.quality`
Expected: PASS.
Run: `make pwa.test.unit c='tests/data-testid-uniqueness.test.ts'`
Expected: PASS (new testids are unique).

- [ ] **Step 5: Commit**

```bash
git add pwa/src/app/_components/Navbar.tsx pwa/src/app/_components/Footer.tsx
git commit -m "feat(frontoffice): link the public status page from navbar and footer"
```

---

### Task 7: Simplify the landing page (remove the health card)

**Files:**
- Modify: `pwa/src/app/page.tsx`

- [ ] **Step 1: Replace the landing page with the slimmed-down version**

Overwrite `pwa/src/app/page.tsx` with exactly this (removes the FrontOffice health card, its state, and the now-unused imports; keeps a single centered BackOffice card):

```tsx
"use client";

import { useState } from "react";
import { useRouter } from "next/navigation";
import { LayoutDashboard } from "lucide-react";
import { Navbar } from "@/app/_components/Navbar";
import { Footer } from "@/app/_components/Footer";
import { FeatureCard } from "@/app/_components/FeatureCard";

export default function LandingPage() {
  const router = useRouter();
  const [loading, setLoading] = useState(false);

  const goToBackOffice = () => {
    setLoading(true);
    setTimeout(() => {
      router.push("/backoffice");
    }, 800);
  };

  return (
    <div className="landing-page min-h-screen flex flex-col bg-slate-50 font-sans">
      <Navbar onGetStarted={goToBackOffice} />

      {/* Main Section */}
      <main className="landing-page__main flex-grow">
        <section className="landing-page__hero max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-12 md:py-24">
          <div className="landing-page__hero-content text-center mb-16">
            <h1 className="landing-page__title text-4xl md:text-6xl font-extrabold text-slate-900 mb-6 tracking-tight animate-in fade-in-0 slide-in-from-bottom-4 duration-700">
              Modern ERP for <span className="text-blue-600">Construction</span>
            </h1>
            <p
              className="landing-page__subtitle text-lg md:text-xl text-slate-600 max-w-2xl mx-auto animate-in fade-in-0 slide-in-from-bottom-4 duration-700"
              style={{ animationDelay: "100ms", animationFillMode: "both" }}
            >
              Streamline your projects, manage your workforce, and track every brick with Erpify.
              The all-in-one solution for construction management.
            </p>
          </div>

          <div className="landing-page__features grid grid-cols-1 gap-8 max-w-md mx-auto">
            <FeatureCard
              title="Admin BackOffice"
              description="Access the powerful dashboard to manage your entire construction operation."
              icon={LayoutDashboard}
              iconColor="text-blue-600"
              iconBg="bg-blue-50"
              buttonText="Go to BackOffice"
              buttonVariant="default"
              onClick={goToBackOffice}
              loading={loading}
            />
          </div>
        </section>
      </main>

      <Footer />
    </div>
  );
}
```

- [ ] **Step 2: Confirm the old health testid is gone from `src/`**

Run: `grep -rn "frontoffice-health-status" pwa/src || echo "CLEAN: literal removed from src"`
Expected: prints `CLEAN: literal removed from src` (the only remaining references are in `pwa/tests/` until Task 8 updates them).

- [ ] **Step 3: Verify lint + format**

Run: `make pwa.quality`
Expected: PASS (no unused-import errors for `CheckHealth`, `dateTimeProvider`, `container`, `Activity`).

- [ ] **Step 4: Commit**

```bash
git add pwa/src/app/page.tsx
git commit -m "refactor(frontoffice): drop inline health card from landing in favor of /status"
```

---

### Task 8: E2E coverage + update existing FrontOffice e2e

**Files:**
- Create: `pwa/tests/e2e/frontoffice/status.spec.ts`
- Modify: `pwa/tests/e2e/helpers/health-assertions.ts`
- Modify: `pwa/tests/e2e/frontoffice/landing.spec.ts`

- [ ] **Step 1: Replace the FrontOffice helper assertion**

In `pwa/tests/e2e/helpers/health-assertions.ts`, replace the `expectFrontOfficeHealthOk` function (keep `expectBackOfficeHealthOk` untouched — it's used by `dashboard.spec.ts`) with:

```ts
export async function expectStatusPageOperational(page: Page): Promise<void> {
  const banner = page.getByTestId("status-page__banner");
  await expect(banner).toBeVisible({ timeout: HEALTH_CHECK_TIMEOUT_MS });
  await expect(banner).toContainText(/All Systems Operational/i);

  const component = page.getByTestId("status-page__component-frontoffice");
  await expect(component).toContainText(/FrontOffice API/i);
  await expect(component).toContainText(/Operational/i);
}
```

- [ ] **Step 2: Add the status page e2e spec**

Create `pwa/tests/e2e/frontoffice/status.spec.ts`:

```ts
import { test, expect } from "@playwright/test";
import { expectStatusPageOperational } from "../helpers/health-assertions";

test.describe("FrontOffice - Status Page", () => {
  test.describe.configure({ mode: "parallel" });

  test.beforeEach(async ({ page }) => {
    await page.goto("/status");
  });

  test("auto-runs the health check and reports all systems operational", async ({ page }) => {
    await expect(page.getByRole("heading", { level: 1, name: /System Status/i })).toBeVisible();
    await expectStatusPageOperational(page);
  });

  test("re-checks via the manual refresh control", async ({ page }) => {
    await expectStatusPageOperational(page);
    await page.getByTestId("status-page__refresh").click();
    await expectStatusPageOperational(page);
  });
});
```

- [ ] **Step 3: Update the landing spec**

Overwrite `pwa/tests/e2e/frontoffice/landing.spec.ts` with exactly this (drops the removed health-button test; adds the Status-link navigation test):

```ts
import { test, expect } from "@playwright/test";

test.describe("FrontOffice - Landing Page", () => {
  test.describe.configure({ mode: "parallel" });

  test.beforeEach(async ({ page }) => {
    await page.goto("/");
  });

  test("displays hero heading", async ({ page }) => {
    await expect(
      page.getByRole("heading", { level: 1, name: /Modern ERP for Construction/i }),
    ).toBeVisible();
  });

  test("navigates to backoffice from primary CTA", async ({ page }) => {
    await page.getByRole("button", { name: "Go to BackOffice" }).click();
    await expect(page).toHaveURL("/backoffice");
  });

  test("navigates to the public status page from the navbar", async ({ page }) => {
    await page.getByTestId("navbar__link-status").click();
    await expect(page).toHaveURL("/status");
    await expect(page.getByRole("heading", { level: 1, name: /System Status/i })).toBeVisible();
  });
});
```

- [ ] **Step 4: Run the FrontOffice e2e (stack must be up)**

Ensure the stack is running (`make app.dev`). Then:
Run: `PLAYWRIGHT_HOST_PLATFORM_OVERRIDE=ubuntu24.04-x64 make pwa.test.e2e c='tests/e2e/frontoffice/status.spec.ts tests/e2e/frontoffice/landing.spec.ts'`
Expected: PASS — 5 tests (2 status + 3 landing). If `expectStatusPageOperational` times out, confirm `https://localhost/api/v1/health` returns `{"data":{"status":"ok",...}}` and the stack is healthy.

- [ ] **Step 5: Commit**

```bash
git add pwa/tests/e2e/frontoffice/status.spec.ts pwa/tests/e2e/helpers/health-assertions.ts pwa/tests/e2e/frontoffice/landing.spec.ts
git commit -m "test(frontoffice): cover the public status page; retarget landing e2e"
```

---

### Task 9: Docs, full quality gates, security self-review

**Files:**
- Modify: `docs/architecture-pwa.md`, `docs/source-tree-analysis.md`, `docs/claude-code-quickref.md`

- [ ] **Step 1: Register the new route in the PWA architecture doc**

Open `docs/architecture-pwa.md`, find the section listing App Router routes / pages (search for `backoffice/health` or `app/` route inventory). Add an entry describing the new public route, e.g.:

> `app/status/` — public, unauthenticated service status page (Atlassian-style). Auto-runs the existing `FrontOfficeCheckHealth` use case on mount and renders an aggregate banner + per-component rows; presentation lives in `app/status/_components/` in the marketing design language. Linked from the navbar and footer. Distinct from the internal admin `app/backoffice/health/` page.

Match the surrounding formatting (bullet/table) rather than pasting verbatim. Use inline code for directory paths — do not create directory-href links (repo Markdown lint rule).

- [ ] **Step 2: Register the new directory in the source-tree + quickref docs**

In `docs/source-tree-analysis.md` and `docs/claude-code-quickref.md`, find where `pwa/src/app/` routes are enumerated and add a one-line entry for `app/status/` (public status page reusing the FrontOffice health use case). Keep the existing style; inline code for paths, no directory-href links.

- [ ] **Step 3: Security self-review (record findings in the eventual PR description)**

Walk the change against the root `CLAUDE.md` frontend checklist and confirm:
- `/status` is an intentional public route (no auth) — consistent with the landing.
- No `dangerouslySetInnerHTML` / `innerHTML` / `eval`; all strings render as escaped JSX text.
- All internal navigation uses static `Routes` constants via `<Link>` / `router.push` — no dynamic/user-influenced URLs, so `safeHref` is not required here.
- Anonymous users see only the friendly disruption message — no `ProblemDetails`, `correlation-id`, or stack traces leak (the page's `catch` discards the error object).
- No new dependencies; no CSP/header changes; the page makes the same same-origin `GET /api/v1/health` the landing already made.

- [ ] **Step 4: Full PWA quality gates**

Run: `make pwa.quality`
Expected: PASS.
Run: `make pwa.test.unit`
Expected: PASS (includes the 3 new status test files and the `data-testid` uniqueness guard).

- [ ] **Step 5: Commit**

```bash
git add docs/architecture-pwa.md docs/source-tree-analysis.md docs/claude-code-quickref.md
git commit -m "docs(frontoffice): document the public status page route"
```

---

## Done criteria

- `/status` is reachable publicly, auto-runs the FrontOffice health check on load, and shows "All Systems Operational" + a "FrontOffice API → Operational" row when healthy; a red "Service Disruption" banner when the API is unreachable.
- A manual "Refresh" re-checks without reload.
- The landing page no longer performs a health check and links to `/status` from the navbar and footer.
- `make pwa.quality` and `make pwa.test.unit` pass; the FrontOffice e2e (`status.spec.ts` + `landing.spec.ts`) passes against the live stack.
- Non-goals stay out: no uptime history, incidents, subscribe, polling/realtime, i18n, or BackOffice on the public page.
