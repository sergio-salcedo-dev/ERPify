"use client";

import { useEffect } from "react";
import Link from "next/link";
import { AlertTriangle, RefreshCw } from "lucide-react";

interface GlobalErrorProps {
  error: Error & { digest?: string };
  reset: () => void;
}

export default function GlobalError({ error, reset }: GlobalErrorProps) {
  useEffect(() => {
    // Last-resort observability hook. The root layout has crashed, so this
    // boundary owns the entire document — keep dependencies minimal to avoid
    // chaining the failure. The real adapter (Sentry, Datadog, …) must scrub
    // PII / secrets before transmission and tolerate a partially-broken DOM.
    if (process.env.NODE_ENV === "development") {
      console.error("[global-error]", error);
    }
  }, [error]);

  const isDev = process.env.NODE_ENV === "development";

  return (
    <html lang="en">
      <body className="bg-background min-h-screen">
        <div className="global-error flex min-h-screen flex-col" data-testid="global-error">
          <main
            role="alert"
            aria-live="assertive"
            className="global-error__main flex flex-1 items-center justify-center px-4 py-16 sm:px-6 lg:px-8"
          >
            <section
              className="global-error__panel bg-card border-border w-full max-w-xl rounded-lg border p-8 text-center shadow-sm sm:p-12"
              data-testid="global-error__panel"
            >
              <div className="global-error__icon-wrap bg-destructive/10 mx-auto flex size-16 items-center justify-center rounded-full">
                <AlertTriangle className="text-destructive size-8" aria-hidden="true" />
              </div>

              <p
                className="global-error__status text-muted-foreground mt-6 text-sm font-medium tracking-wider uppercase"
                data-testid="global-error__status"
              >
                Critical error
              </p>

              <h1
                className="global-error__title text-foreground mt-2 text-3xl font-semibold tracking-tight sm:text-4xl"
                data-testid="global-error__title"
              >
                The application could not start
              </h1>

              <p
                className="global-error__description text-muted-foreground mx-auto mt-4 max-w-md text-sm sm:text-base"
                data-testid="global-error__description"
              >
                A critical failure occurred while loading Erpify. The team has been notified. Try
                again, and if the issue persists contact support with the reference below.
              </p>

              {isDev && error?.message ? (
                <pre
                  className="global-error__details bg-muted text-foreground border-border mt-6 max-h-48 overflow-auto rounded-md border p-4 text-left font-mono text-xs whitespace-pre-wrap"
                  data-testid="global-error__details"
                >
                  {error.message}
                </pre>
              ) : null}

              {error?.digest ? (
                <div
                  className="global-error__digest border-border bg-muted/40 mt-6 flex flex-col items-center justify-center gap-2 rounded-md border p-3 sm:flex-row"
                  data-testid="global-error__digest"
                >
                  <span className="global-error__digest-label text-muted-foreground text-xs font-medium tracking-wider uppercase">
                    Error ID
                  </span>
                  <code
                    className="global-error__digest-value text-foreground font-mono text-xs break-all select-all"
                    data-testid="global-error__digest-value"
                  >
                    {error.digest}
                  </code>
                </div>
              ) : null}

              <div className="global-error__actions mt-8 flex flex-col items-center justify-center gap-3 sm:flex-row">
                <button
                  type="button"
                  onClick={() => reset()}
                  className="global-error__retry bg-primary text-primary-foreground hover:bg-primary/90 inline-flex h-10 items-center justify-center gap-2 rounded-lg px-5 text-sm font-medium transition-colors"
                  title="Retry loading the application"
                  aria-label="Try again"
                  data-testid="global-error__retry"
                >
                  <RefreshCw className="size-4" aria-hidden="true" />
                  Try again
                </button>
                <Link
                  href="/"
                  className="global-error__home-link border-border text-foreground hover:bg-muted inline-flex h-10 items-center justify-center rounded-lg border px-5 text-sm font-medium transition-colors"
                  title="Reload home"
                  aria-label="Home"
                  data-testid="global-error__home-link"
                >
                  Reload home
                </Link>
              </div>
            </section>
          </main>
        </div>
      </body>
    </html>
  );
}
