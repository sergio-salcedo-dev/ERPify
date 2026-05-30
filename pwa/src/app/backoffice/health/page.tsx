"use client";

import { useState } from "react";
import { Activity, ShieldCheck } from "lucide-react";
import { container } from "@/context/shared/infrastructure/DependencyInjection/Container";
import { dateTimeProvider } from "@/context/shared/infrastructure/DateTimeProvider";
import { CheckHealth } from "@/context/backoffice/health/application/CheckHealth";
import type { HealthCheck } from "@/context/backoffice/health/domain/HealthCheck";
import type { ProblemDetails } from "@/context/shared/domain/ProblemDetails";
import { ApiStatus, ViewStatus } from "@/context/shared/domain/types/status";
import { HttpError } from "@/context/shared/infrastructure/HttpClient/HttpError";
import { AsyncBoundary, type AsyncBoundaryState } from "@/components/erpify";
import { Button } from "@/components/ui/button";

function transportFailureProblem(detail: string): ProblemDetails {
  return {
    type: "health-check-failed",
    title: "Health check failed",
    status: 0,
    detail,
    instance: crypto.randomUUID(),
    "correlation-id": crypto.randomUUID(),
  };
}

export default function HealthPage() {
  const [state, setState] = useState<AsyncBoundaryState>(ApiStatus.IDLE);
  const [data, setData] = useState<HealthCheck | null>(null);
  const [problem, setProblem] = useState<ProblemDetails | null>(null);

  async function runCheck(): Promise<void> {
    setState(ViewStatus.LOADING);
    setProblem(null);
    try {
      const useCase = container.get<CheckHealth>("BackOfficeCheckHealth");
      const result = await useCase.run();
      setData(result);
      setState(ViewStatus.READY);
    } catch (err) {
      const detail = err instanceof Error ? err.message : "Unknown error";
      setProblem(err instanceof HttpError ? err.problem : transportFailureProblem(detail));
      setState(ViewStatus.ERROR);
    }
  }

  return (
    <div className="health-page space-y-8">
      <header className="health-page__header flex flex-col gap-1 md:flex-row md:items-center md:justify-between">
        <div>
          <h1 className="text-foreground text-2xl font-semibold tracking-tight">System Health</h1>
          <p className="text-muted-foreground mt-1 text-sm">
            Monitor and verify the status of your BackOffice API services.
          </p>
        </div>
      </header>

      <section className="bg-card border-border space-y-6 rounded-lg border p-6">
        <div className="flex flex-col items-center justify-center gap-4 py-8 text-center">
          <div className="bg-primary/10 rounded-full p-3">
            <ShieldCheck className="text-primary size-8" aria-hidden="true" />
          </div>
          <h2 className="text-foreground text-base font-semibold">API Connectivity</h2>
          <p className="text-muted-foreground max-w-md text-sm">
            Perform a real-time health check to ensure all backend services are responding
            correctly.
          </p>
          <Button onClick={runCheck} disabled={state === ViewStatus.LOADING} size="lg">
            <Activity
              className={`size-4 ${state === ViewStatus.LOADING ? "animate-pulse" : ""}`}
              aria-hidden="true"
            />
            {state === ViewStatus.LOADING ? "Checking API..." : "Run Health Check"}
          </Button>
        </div>

        {state === ApiStatus.IDLE ? null : (
          <AsyncBoundary state={state} data={data ?? undefined} error={problem ?? undefined}>
            {(result) => (
              <div
                data-testid="backoffice-health-status"
                className="bg-success/10 border-success/30 text-foreground flex items-center gap-3 rounded-md border p-4 font-mono text-xs"
              >
                <span className="bg-success size-2 shrink-0 rounded-full" aria-hidden="true" />
                <span>
                  Status: {result.status} | Service: {result.service} | Date:{" "}
                  {dateTimeProvider.formatIsoToLocalDateTime(result.datetime)}
                </span>
              </div>
            )}
          </AsyncBoundary>
        )}
      </section>
    </div>
  );
}
