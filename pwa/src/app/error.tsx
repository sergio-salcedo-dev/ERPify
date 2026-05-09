"use client";

import { useEffect } from "react";
import Link from "next/link";
import { AlertTriangle, Home, RefreshCw } from "lucide-react";
import { Button } from "@/components/ui/button";
import { buttonVariants } from "@/components/ui/button-variants";
import { CopyButton, ErrorScreen } from "@/components/erpify";
import { cn } from "@/lib/utils";

interface ErrorBoundaryProps {
  error: Error & { digest?: string };
  reset: () => void;
}

const ACTION_BTN = "h-11 w-full px-6 text-base sm:w-auto sm:h-10 lg:h-11 lg:text-base";

export default function GlobalError({ error, reset }: ErrorBoundaryProps) {
  useEffect(() => {
    // Placeholder hook for an external observability sink (Sentry, Datadog, …).
    // The real adapter must scrub PII / secrets before transmission and run
    // server-side where possible — never ship raw stack traces to a 3rd party
    // from the browser without explicit consent.
    if (process.env.NODE_ENV === "development") {
      console.error("[error-boundary]", error);
    }
  }, [error]);

  const isDev = process.env.NODE_ENV === "development";

  return (
    <ErrorScreen
      testIdPrefix="error-page"
      status="Error 500"
      title="Something went wrong"
      description="We hit an unexpected problem while processing your request. The team has been notified. Please try again, and if the issue persists contact support."
      icon={AlertTriangle}
      iconTone="destructive"
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
            className={cn(ACTION_BTN, "error-page__retry")}
          >
            <RefreshCw className="size-4" aria-hidden="true" />
            Try again
          </Button>
          <Link
            href="/"
            className={cn(
              buttonVariants({ variant: "outline", size: "lg" }),
              ACTION_BTN,
              "error-page__home-link",
            )}
            data-icon="inline-start"
            title="Return to home"
            aria-label="Home"
            data-testid="error-page__home-link"
          >
            <Home className="size-4" aria-hidden="true" />
            Return home
          </Link>
        </>
      }
    />
  );
}
