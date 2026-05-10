"use client";

import { useEffect } from "react";
import { AlertTriangle } from "lucide-react";
import { ErrorActions, ErrorScreen } from "@/context/shared/error/infrastructure/ui";
import { IconTone } from "@/context/shared/error/domain/IconTone";
import { NodeEnv } from "@/context/shared/domain/types/nodeEnv";

interface GlobalErrorProps {
  error: Error & { digest?: string };
  /** Provided by Next's error boundary contract; intentionally not wired to a UI control — the
   *  shared {@link ErrorActions} row already exposes the canonical "Go back" recovery path. */
  reset: () => void;
}

export default function GlobalError({ error }: GlobalErrorProps) {
  useEffect(() => {
    // Last-resort observability hook. The root layout has crashed, so this
    // boundary owns the entire document — keep dependencies minimal to avoid
    // chaining the failure. The real adapter (Sentry, Datadog, …) must scrub
    // PII / secrets before transmission and tolerate a partially-broken DOM.
    if (process.env.NODE_ENV === NodeEnv.DEVELOPMENT) {
      console.error("[global-error]", error);
    }
  }, [error]);

  const isDev = process.env.NODE_ENV === NodeEnv.DEVELOPMENT;

  return (
    <html lang="en">
      <body className="bg-background min-h-screen">
        <ErrorScreen
          testIdPrefix="global-error"
          status="Critical error"
          title="The application could not start"
          description="A critical failure occurred while loading Erpify. The team has been notified. Try again, and if the issue persists contact support with the reference below."
          icon={AlertTriangle}
          iconTone={IconTone.DESTRUCTIVE}
          mainRole="alert"
          withHeader={false}
          extras={
            <>
              {isDev && error?.message ? (
                <pre
                  className="global-error__details bg-muted text-foreground border-border mb-4 max-h-40 overflow-auto rounded-md border p-3 text-left font-mono text-xs whitespace-pre-wrap sm:max-h-48 sm:p-4"
                  data-testid="global-error__details"
                >
                  {error.message}
                </pre>
              ) : null}

              {error?.digest ? (
                <div
                  className="global-error__digest border-border bg-muted/40 flex flex-col items-center justify-center gap-2 rounded-md border p-3 sm:flex-row"
                  data-testid="global-error__digest"
                >
                  <span className="global-error__digest-label text-muted-foreground text-[11px] font-medium tracking-wider uppercase sm:text-xs">
                    Error ID
                  </span>
                  <code
                    className="global-error__digest-value text-foreground max-w-full font-mono text-[11px] break-all select-all sm:text-xs"
                    data-testid="global-error__digest-value"
                  >
                    {error.digest}
                  </code>
                </div>
              ) : null}
            </>
          }
          actions={<ErrorActions />}
        />
      </body>
    </html>
  );
}
