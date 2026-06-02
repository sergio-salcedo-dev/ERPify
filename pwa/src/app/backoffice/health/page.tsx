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
