"use client";

import { useCallback, useEffect, useRef, useState } from "react";
import { RefreshCw } from "lucide-react";
import { container } from "@/context/shared/dependency-injection/infrastructure/Container";
import type { HealthCheck } from "@/context/backoffice/health/domain/HealthCheck";
import type { ProblemDetails } from "@/context/shared/error/domain/ProblemDetails";
import { HttpError } from "@/context/shared/http-client/domain/HttpError";
import { apiScope } from "@/context/shared/observability/domain/TelemetryScope";
import { telemetry } from "@/context/shared/observability/infrastructure";
import {
  aggregateSystemStatus,
  componentRollCall,
  deriveSystemStatus,
} from "@/context/shared/system-status/domain/SystemStatus";
import { uuidV7 } from "@/context/shared/uuid/infrastructure/uuidV7";
import { Button } from "@/components/ui/button";
import { ProblemDisplay } from "@/components/erpify";
import { SystemStatusBanner } from "./_components/SystemStatusBanner";
import { HealthComponentRow } from "./_components/HealthComponentRow";

/** Structural view of any back-office health use case (API or database). */
interface HealthUseCase {
  run(): Promise<HealthCheck>;
}

interface MonitoredComponent {
  key: string;
  name: string;
  useCase: string;
  scope: string;
}

const MONITORED_COMPONENTS: readonly MonitoredComponent[] = [
  {
    key: "backoffice",
    name: "BackOffice API",
    useCase: "BackOfficeCheckHealth",
    scope: "backoffice-health",
  },
  {
    key: "database",
    name: "Database",
    useCase: "BackOfficeCheckDatabaseHealth",
    scope: "backoffice-database-health",
  },
];

interface ComponentCheck {
  result: HealthCheck | null;
  problem: ProblemDetails | null;
}

const PENDING: ComponentCheck = { result: null, problem: null };

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

async function runComponentCheck(useCase: string, scope: string): Promise<ComponentCheck> {
  try {
    const result = await container.get<HealthUseCase>(useCase).run();
    return { result, problem: null };
  } catch (err) {
    const detail = err instanceof Error ? err.message : "Unknown error";
    telemetry.warn("BackOffice health check failed", { scope: apiScope(scope), cause: err });
    return {
      result: null,
      problem: err instanceof HttpError ? err.problem : transportFailureProblem(detail),
    };
  }
}

export default function HealthPage() {
  const [checks, setChecks] = useState<Record<string, ComponentCheck>>({});
  const [checking, setChecking] = useState(true);
  const mountedRef = useRef(true);

  const runCheck = useCallback(async () => {
    setChecking(true);
    const settled = await Promise.all(
      MONITORED_COMPONENTS.map(async (component) => {
        const check = await runComponentCheck(component.useCase, component.scope);
        return [component.key, check] as const;
      }),
    );
    if (!mountedRef.current) return;
    setChecks(Object.fromEntries(settled));
    setChecking(false);
  }, []);

  useEffect(() => {
    mountedRef.current = true;
    // eslint-disable-next-line react-hooks/set-state-in-effect
    runCheck();
    return () => {
      mountedRef.current = false;
    };
  }, [runCheck]);

  const componentViews = MONITORED_COMPONENTS.map((component) => {
    const check = checks[component.key] ?? PENDING;
    const view = deriveSystemStatus({
      checking,
      failed: check.problem !== null,
      result: check.result,
    });
    return { component, check, view };
  });

  // The banner announces the worst status only, so a component that moves under it changes a row
  // and nothing else. Read the roll call out with it, in the banner's own region.
  const rollCall = componentRollCall(
    componentViews.map(({ component, view }) => ({ name: component.name, status: view.status })),
  );

  const bannerView = checking
    ? {
        status: deriveSystemStatus({ checking, failed: false, result: null }).status,
        datetime: null,
      }
    : aggregateSystemStatus(componentViews.map(({ view }) => view));

  return (
    <div className="health-page space-y-8">
      <header className="health-page__header flex flex-col gap-1">
        <h1 className="text-foreground text-2xl font-semibold tracking-tight">System Health</h1>
        <p className="text-muted-foreground text-sm">Live health of your back-office services.</p>
      </header>

      <section className="health-page__status bg-card border-border space-y-6 rounded-lg border p-6">
        <SystemStatusBanner
          status={bannerView.status}
          datetime={bannerView.datetime}
          detail={rollCall ? `${rollCall}.` : undefined}
          testId="backoffice-health__banner"
        />

        <div className="health-page__components">
          <h2 className="text-muted-foreground mb-2 text-xs font-semibold tracking-wide uppercase">
            Components
          </h2>
          {componentViews.map(({ component, view }) => (
            <HealthComponentRow
              key={component.key}
              name={component.name}
              status={view.status}
              testId={`backoffice-health__component-${component.key}`}
            />
          ))}
        </div>

        {componentViews.map(({ component, check }) =>
          check.problem ? (
            <ProblemDisplay key={component.key} variant="inline" problem={check.problem} />
          ) : null,
        )}

        <div className="health-page__actions flex justify-end">
          <Button
            variant="ghost"
            onClick={() => {
              runCheck();
            }}
            disabled={checking}
            title="Re-check service status"
            aria-label="Refresh"
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
