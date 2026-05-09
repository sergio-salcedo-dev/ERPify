"use client";

import { useEffect } from "react";
import Link from "next/link";
import { AlertTriangle, Home, RefreshCw, ShieldCheck } from "lucide-react";
import { Button, buttonVariants } from "@/components/ui/button";
import { CopyButton } from "@/components/erpify";
import { cn } from "@/lib/utils";

interface ErrorBoundaryProps {
  error: Error & { digest?: string };
  reset: () => void;
}

export default function GlobalError({ error, reset }: ErrorBoundaryProps) {
  useEffect(() => {
    // Placeholder hook for an external observability sink (Sentry, Datadog, …).
    // The real adapter must scrub PII / secrets before transmission and run
    // server-side where possible — never ship raw stack traces to a 3rd party
    // from the browser without explicit consent.
    // Example:
    //   reportError({ message: error.message, digest: error.digest });
    if (process.env.NODE_ENV === "development") {
      console.error("[error-boundary]", error);
    }
  }, [error]);

  const isDev = process.env.NODE_ENV === "development";

  return (
    <div className="error-page bg-background flex min-h-screen flex-col" data-testid="error-page">
      <header className="error-page__header border-border bg-card border-b">
        <div className="error-page__header-inner mx-auto flex max-w-7xl items-center gap-2 px-4 py-4 sm:px-6 lg:px-8">
          <div className="error-page__logo-mark bg-primary rounded-lg p-2">
            <ShieldCheck className="text-primary-foreground size-5" aria-hidden="true" />
          </div>
          <span className="error-page__logo-text text-foreground text-lg font-semibold tracking-tight">
            Erpify
          </span>
        </div>
      </header>

      <main
        role="alert"
        aria-live="assertive"
        className="error-page__main flex flex-1 items-center justify-center px-4 py-16 sm:px-6 lg:px-8"
      >
        <section
          className="error-page__panel bg-card border-border w-full max-w-xl rounded-lg border p-8 text-center shadow-sm sm:p-12"
          data-testid="error-page__panel"
        >
          <div className="error-page__icon-wrap bg-destructive/10 mx-auto flex size-16 items-center justify-center rounded-full">
            <AlertTriangle className="text-destructive size-8" aria-hidden="true" />
          </div>

          <p
            className="error-page__status text-muted-foreground mt-6 text-sm font-medium tracking-wider uppercase"
            data-testid="error-page__status"
          >
            Error 500
          </p>

          <h1
            className="error-page__title text-foreground mt-2 text-3xl font-semibold tracking-tight sm:text-4xl"
            data-testid="error-page__title"
          >
            Something went wrong
          </h1>

          <p
            className="error-page__description text-muted-foreground mx-auto mt-4 max-w-md text-sm sm:text-base"
            data-testid="error-page__description"
          >
            We hit an unexpected problem while processing your request. The team has been notified.
            Please try again, and if the issue persists contact support.
          </p>

          {isDev && error?.message ? (
            <pre
              className="error-page__details bg-muted text-foreground border-border mt-6 max-h-48 overflow-auto rounded-md border p-4 text-left font-mono text-xs whitespace-pre-wrap"
              data-testid="error-page__details"
            >
              {error.message}
            </pre>
          ) : null}

          {error?.digest ? (
            <div
              className="error-page__digest border-border bg-muted/40 mt-6 flex flex-col items-center justify-center gap-2 rounded-md border p-3 sm:flex-row"
              data-testid="error-page__digest"
            >
              <span className="error-page__digest-label text-muted-foreground text-xs font-medium tracking-wider uppercase">
                Error ID
              </span>
              <code
                className="error-page__digest-value text-foreground font-mono text-xs break-all select-all"
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

          <div className="error-page__actions mt-8 flex flex-col items-center justify-center gap-3 sm:flex-row">
            <Button
              type="button"
              size="lg"
              onClick={() => reset()}
              title="Retry the failing operation"
              aria-label="Try again"
              data-testid="error-page__retry"
            >
              <RefreshCw className="size-4" aria-hidden="true" />
              Try again
            </Button>
            <Link
              href="/"
              className={cn(
                buttonVariants({ variant: "outline", size: "lg" }),
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
          </div>
        </section>
      </main>
    </div>
  );
}
