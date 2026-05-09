import Link from "next/link";
import { Home, Lock, ShieldCheck } from "lucide-react";
import { buttonVariants } from "@/components/ui/button";
import { cn } from "@/lib/utils";

export const metadata = {
  title: "Unauthorized · Erpify",
  description: "You do not have permission to access this resource.",
};

export default function UnauthorizedPage() {
  return (
    <div
      className="unauthorized bg-background flex min-h-screen flex-col"
      data-testid="unauthorized"
    >
      <header className="unauthorized__header border-border bg-card border-b">
        <div className="unauthorized__header-inner mx-auto flex max-w-7xl items-center gap-2 px-4 py-4 sm:px-6 lg:px-8">
          <div className="unauthorized__logo-mark bg-primary rounded-lg p-2">
            <ShieldCheck className="text-primary-foreground size-5" aria-hidden="true" />
          </div>
          <span className="unauthorized__logo-text text-foreground text-lg font-semibold tracking-tight">
            Erpify
          </span>
        </div>
      </header>

      <main className="unauthorized__main flex flex-1 items-center justify-center px-4 py-16 sm:px-6 lg:px-8">
        <section
          className="unauthorized__panel bg-card border-border w-full max-w-xl rounded-lg border p-8 text-center shadow-sm sm:p-12"
          data-testid="unauthorized__panel"
        >
          <div className="unauthorized__icon-wrap bg-warning/10 mx-auto flex size-16 items-center justify-center rounded-full">
            <Lock className="text-warning size-8" aria-hidden="true" />
          </div>

          <p
            className="unauthorized__status text-muted-foreground mt-6 text-sm font-medium tracking-wider uppercase"
            data-testid="unauthorized__status"
          >
            Error 403
          </p>

          <h1
            className="unauthorized__title text-foreground mt-2 text-3xl font-semibold tracking-tight sm:text-4xl"
            data-testid="unauthorized__title"
          >
            Access denied
          </h1>

          <p
            className="unauthorized__description text-muted-foreground mx-auto mt-4 max-w-md text-sm sm:text-base"
            data-testid="unauthorized__description"
          >
            You do not have permission to view this resource. If you believe this is a mistake,
            contact your administrator to request the appropriate access.
          </p>

          <div className="unauthorized__actions mt-8 flex flex-col items-center justify-center gap-3 sm:flex-row">
            <Link
              href="/"
              className={cn(buttonVariants({ size: "lg" }), "unauthorized__home-link")}
              data-icon="inline-start"
              title="Return to home"
              aria-label="Home"
              data-testid="unauthorized__home-link"
            >
              <Home className="size-4" aria-hidden="true" />
              Return home
            </Link>
            <Link
              href="/backoffice"
              className={cn(
                buttonVariants({ variant: "outline", size: "lg" }),
                "unauthorized__backoffice-link",
              )}
              title="Go to BackOffice"
              aria-label="BackOffice"
              data-testid="unauthorized__backoffice-link"
            >
              Go to BackOffice
            </Link>
          </div>
        </section>
      </main>
    </div>
  );
}
