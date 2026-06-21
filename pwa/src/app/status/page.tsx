"use client";

import { useCallback, useEffect, useState } from "react";
import { useRouter } from "next/navigation";
import { RefreshCw } from "lucide-react";
import { container } from "@/context/shared/dependency-injection/infrastructure/Container";
import { CheckHealth } from "@/context/frontoffice/health/application/CheckHealth";
import type { HealthCheck } from "@/context/frontoffice/health/domain/HealthCheck";
import { Routes } from "@/context/shared/routing/domain/Routes";
import { apiScope } from "@/context/shared/observability/domain/TelemetryScope";
import { telemetry } from "@/context/shared/observability/infrastructure";
import { Navbar } from "@/app/_components/Navbar";
import { Footer } from "@/app/_components/Footer";
import { Button } from "@/components/ui/button";
import { StatusBanner } from "./_components/StatusBanner";
import { ComponentStatusRow } from "./_components/ComponentStatusRow";
import { deriveSystemStatus } from "@/context/shared/system-status/domain/SystemStatus";

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
    } catch (err) {
      // Ops-only diagnostics (silent in prod via the telemetry port); the
      // anonymous user only ever sees the generic DISRUPTED banner.
      telemetry.warn("FrontOffice health check failed", {
        scope: apiScope("frontoffice-health"),
        cause: err,
      });
      setResult(null);
      setFailed(true);
    } finally {
      setChecking(false);
    }
  }, []);

  useEffect(() => {
    // runCheck resets checking/failed before its first await; that initial
    // setState is intentional (it also drives the manual Retry path) and runs
    // through a stable callback, so the cascading-render warning does not apply.
    // eslint-disable-next-line react-hooks/set-state-in-effect
    runCheck();
  }, [runCheck]);

  const view = deriveSystemStatus({ checking, failed, result });

  return (
    <div className="status-page flex min-h-screen flex-col bg-background font-sans">
      <Navbar goToBackoffice={() => router.push(Routes.BACKOFFICE)} />

      <main className="status-page__main flex-grow">
        <section className="status-page__content mx-auto max-w-3xl px-4 py-12 sm:px-6 md:py-20 lg:px-8">
          <header className="status-page__header mb-8 text-center">
            <h1 className="status-page__title text-3xl font-extrabold tracking-tight text-foreground md:text-4xl">
              System Status
            </h1>
            <p className="status-page__subtitle mt-3 text-muted-foreground">
              Live availability of Erpify services.
            </p>
          </header>

          <StatusBanner
            status={view.status}
            datetime={view.datetime}
            testId="status-page__banner"
          />

          <div className="status-page__components mt-8 rounded-2xl border border-border bg-card p-6 shadow-sm">
            <h2 className="status-page__components-title mb-2 text-sm font-semibold tracking-wide text-muted-foreground uppercase">
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
              onClick={() => {
                runCheck();
              }}
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
