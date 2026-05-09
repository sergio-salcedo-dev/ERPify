import Link from "next/link";
import { Home, Hourglass, ShieldCheck } from "lucide-react";
import { buttonVariants } from "@/components/ui/button";
import { cn } from "@/lib/utils";

export const metadata = {
  title: "Too many requests · Erpify",
  description: "You have reached the request limit. Please wait and try again.",
};

export default function RateLimitedPage() {
  return (
    <div
      className="rate-limited bg-background flex min-h-screen flex-col"
      data-testid="rate-limited"
    >
      <header className="rate-limited__header border-border bg-card border-b">
        <div className="rate-limited__header-inner mx-auto flex max-w-7xl items-center gap-2 px-4 py-4 sm:px-6 lg:px-8">
          <div className="rate-limited__logo-mark bg-primary rounded-lg p-2">
            <ShieldCheck className="text-primary-foreground size-5" aria-hidden="true" />
          </div>
          <span className="rate-limited__logo-text text-foreground text-lg font-semibold tracking-tight">
            Erpify
          </span>
        </div>
      </header>

      <main className="rate-limited__main flex flex-1 items-center justify-center px-4 py-16 sm:px-6 lg:px-8">
        <section
          className="rate-limited__panel bg-card border-border w-full max-w-xl rounded-lg border p-8 text-center shadow-sm sm:p-12"
          data-testid="rate-limited__panel"
        >
          <div className="rate-limited__icon-wrap bg-warning/10 mx-auto flex size-16 items-center justify-center rounded-full">
            <Hourglass className="text-warning size-8" aria-hidden="true" />
          </div>

          <p
            className="rate-limited__status text-muted-foreground mt-6 text-sm font-medium tracking-wider uppercase"
            data-testid="rate-limited__status"
          >
            Error 429
          </p>

          <h1
            className="rate-limited__title text-foreground mt-2 text-3xl font-semibold tracking-tight sm:text-4xl"
            data-testid="rate-limited__title"
          >
            Too many requests
          </h1>

          <p
            className="rate-limited__description text-muted-foreground mx-auto mt-4 max-w-md text-sm sm:text-base"
            data-testid="rate-limited__description"
          >
            You&apos;ve hit our request limit. Please wait a few moments before trying again. If
            you&apos;re running automation, slow down or contact support to request higher quotas.
          </p>

          <div className="rate-limited__actions mt-8 flex flex-col items-center justify-center gap-3 sm:flex-row">
            <Link
              href="/"
              className={cn(buttonVariants({ size: "lg" }), "rate-limited__home-link")}
              data-icon="inline-start"
              title="Return to home"
              aria-label="Home"
              data-testid="rate-limited__home-link"
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
