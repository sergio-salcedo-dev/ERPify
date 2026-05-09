import Link from "next/link";
import { Home, ShieldCheck, Wrench } from "lucide-react";
import { buttonVariants } from "@/components/ui/button";
import { cn } from "@/lib/utils";

export const metadata = {
  title: "Scheduled maintenance · Erpify",
  description: "Erpify is currently undergoing scheduled maintenance.",
};

export default function MaintenancePage() {
  return (
    <div className="maintenance bg-background flex min-h-screen flex-col" data-testid="maintenance">
      <header className="maintenance__header border-border bg-card border-b">
        <div className="maintenance__header-inner mx-auto flex max-w-7xl items-center gap-2 px-4 py-4 sm:px-6 lg:px-8">
          <div className="maintenance__logo-mark bg-primary rounded-lg p-2">
            <ShieldCheck className="text-primary-foreground size-5" aria-hidden="true" />
          </div>
          <span className="maintenance__logo-text text-foreground text-lg font-semibold tracking-tight">
            Erpify
          </span>
        </div>
      </header>

      <main className="maintenance__main flex flex-1 items-center justify-center px-4 py-16 sm:px-6 lg:px-8">
        <section
          className="maintenance__panel bg-card border-border w-full max-w-xl rounded-lg border p-8 text-center shadow-sm sm:p-12"
          data-testid="maintenance__panel"
        >
          <div className="maintenance__icon-wrap bg-warning/10 mx-auto flex size-16 items-center justify-center rounded-full">
            <Wrench className="text-warning size-8" aria-hidden="true" />
          </div>

          <p
            className="maintenance__status text-muted-foreground mt-6 text-sm font-medium tracking-wider uppercase"
            data-testid="maintenance__status"
          >
            Error 503
          </p>

          <h1
            className="maintenance__title text-foreground mt-2 text-3xl font-semibold tracking-tight sm:text-4xl"
            data-testid="maintenance__title"
          >
            Scheduled maintenance
          </h1>

          <p
            className="maintenance__description text-muted-foreground mx-auto mt-4 max-w-md text-sm sm:text-base"
            data-testid="maintenance__description"
          >
            Erpify is temporarily offline while we deploy improvements. We&apos;ll be back shortly —
            thank you for your patience.
          </p>

          <div className="maintenance__actions mt-8 flex flex-col items-center justify-center gap-3 sm:flex-row">
            <Link
              href="/"
              className={cn(buttonVariants({ size: "lg" }), "maintenance__home-link")}
              data-icon="inline-start"
              title="Return to home"
              aria-label="Home"
              data-testid="maintenance__home-link"
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
