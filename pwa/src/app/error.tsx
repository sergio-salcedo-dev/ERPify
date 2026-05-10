"use client";

import { useEffect } from "react";
import { AlertTriangle, RefreshCw } from "lucide-react";
import { CopyButton } from "@/components/erpify";
import { Button } from "@/components/ui/button";
import {
  ERROR_ACTION_BTN_CLASSES,
  ErrorActions,
  ErrorScreen,
} from "@/context/shared/error/infrastructure/ui";
import { IconTone } from "@/context/shared/error/domain/IconTone";
import { NodeEnv } from "@/context/shared/domain/types/nodeEnv";
import { cn } from "@/lib/utils";

interface ErrorBoundaryProps {
  error: Error & { digest?: string };
  /** Next.js boundary `reset()` — re-renders the failing subtree without a navigation. */
  reset: () => void;
}

export default function GlobalError({ error, reset }: ErrorBoundaryProps) {
  useEffect(() => {
    // Placeholder hook for an external observability sink (Sentry, Datadog, …).
    // The real adapter must scrub PII / secrets before transmission and run
    // server-side where possible — never ship raw stack traces to a 3rd party
    // from the browser without explicit consent.
    if (process.env.NODE_ENV === NodeEnv.DEVELOPMENT) {
      console.error("[error-boundary]", error);
    }
  }, [error]);

  const isDev = process.env.NODE_ENV === NodeEnv.DEVELOPMENT;

  return (
    <ErrorScreen
      testIdPrefix="error-page"
      status="Error 500"
      title="Something went wrong"
      description="We hit an unexpected problem while processing your request. The team has been notified. Please try again, and if the issue persists contact support."
      icon={AlertTriangle}
      iconTone={IconTone.DESTRUCTIVE}
      mainRole="alert"
      extras={
        <>
          {isDev && error?.message ? (
            <pre
              className="error-page__details bg-muted text-foreground border-border mb-4 max-h-40 overflow-auto rounded-md border p-3 text-left font-mono text-xs whitespace-pre-wrap sm:max-h-48 sm:p-4"
              data-testid="error-page__details"
            >
              {error.message}
            </pre>
          ) : null}

          {error?.digest ? (
            <div
              className="error-page__digest border-border bg-muted/40 flex flex-col items-center justify-center gap-2 rounded-md border p-3 sm:flex-row"
              data-testid="error-page__digest"
            >
              <span className="error-page__digest-label text-muted-foreground text-[11px] font-medium tracking-wider uppercase sm:text-xs">
                Error ID
              </span>
              <code
                className="error-page__digest-value text-foreground max-w-full font-mono text-[11px] break-all select-all sm:text-xs"
                data-testid="error-page__digest-value"
              >
                {error.digest}
              </code>
              <CopyButton
                value={error.digest}
                label="Copy"
                copiedLabel="Copied"
                size="xs"
                variant="ghost"
                iconOnly
                className="error-page__digest-copy data-[copy-status=copied]:[&_svg]:text-success"
                testId="error-page__digest-copy"
              />
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
            title="Retry the failing operation"
            aria-label="Try again"
            data-testid="error-page__retry"
            className={cn(ERROR_ACTION_BTN_CLASSES, "error-page__retry")}
          >
            <RefreshCw className="size-4" aria-hidden="true" />
            Try again
          </Button>
          <ErrorActions primaryVariant="outline" />
        </>
      }
    />
  );
}
