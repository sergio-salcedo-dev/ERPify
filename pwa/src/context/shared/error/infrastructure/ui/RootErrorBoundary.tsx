"use client";

import { useEffect } from "react";
import { AlertTriangle, RefreshCw } from "lucide-react";
import { Button } from "@/components/ui/button";
import { IconTone } from "@/context/shared/error/domain/IconTone";
import { NodeEnv } from "@/context/shared/domain/types/nodeEnv";
import { cn } from "@/lib/utils";
import { ERROR_ACTION_BTN_CLASSES, ErrorActions } from "./ErrorActions";
import { ErrorScreen } from "./ErrorScreen";

interface RootErrorBoundaryProps {
  error: Error & { digest?: string };
  /** Next.js boundary `reset()` — re-renders the failing subtree without a navigation. */
  reset: () => void;
}

/**
 * Branded UI for Next.js's root `global-error.tsx` boundary — fires only
 * when `RootLayout` itself crashes, so this component owns the entire
 * document and renders its own `<html>` / `<body>` (the rest of the
 * error module gates its outer chrome via `withHeader={false}`).
 *
 * The Next convention file at `app/global-error.tsx` is a thin re-export
 * of this component. Same redaction guarantees as
 * `<SegmentErrorBoundary>`: `error.message` is gated to development only,
 * the digest stays visible because it's an opaque correlation hash.
 *
 * Dependencies are kept minimal here on purpose — the root layout has
 * already crashed once, so we want to avoid pulling extras (e.g. the
 * `<CopyButton>` clipboard shim) that could chain the failure.
 */
export function RootErrorBoundary({ error, reset }: Readonly<RootErrorBoundaryProps>) {
  useEffect(() => {
    // Last-resort observability hook. The real adapter (Sentry, Datadog, …)
    // must scrub PII / secrets before transmission and tolerate a partially-
    // broken DOM. Never ship raw stack traces to a 3rd party from the browser
    // without explicit consent.
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
          actions={
            <>
              <Button
                type="button"
                size="lg"
                onClick={() => reset()}
                title="Retry loading the application"
                aria-label="Try again"
                data-testid="global-error__retry"
                className={cn(ERROR_ACTION_BTN_CLASSES, "global-error__retry")}
              >
                <RefreshCw className="size-4" aria-hidden="true" />
                Try again
              </Button>
              <ErrorActions primaryVariant="outline" />
            </>
          }
        />
      </body>
    </html>
  );
}
