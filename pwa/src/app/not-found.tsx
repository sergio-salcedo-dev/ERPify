import Link from "next/link";
import { FileQuestion, Home, ShieldCheck } from "lucide-react";
import { buttonVariants } from "@/components/ui/button";
import { cn } from "@/lib/utils";

export default function NotFound() {
  return (
    <div className="not-found bg-background flex min-h-screen flex-col" data-testid="not-found">
      <header className="not-found__header border-border bg-card border-b">
        <div className="not-found__header-inner mx-auto flex max-w-7xl items-center gap-2 px-4 py-4 sm:px-6 lg:px-8">
          <div className="not-found__logo-mark bg-primary rounded-lg p-2">
            <ShieldCheck className="text-primary-foreground size-5" aria-hidden="true" />
          </div>
          <span className="not-found__logo-text text-foreground text-lg font-semibold tracking-tight">
            Erpify
          </span>
        </div>
      </header>

      <main className="not-found__main flex flex-1 items-center justify-center px-4 py-16 sm:px-6 lg:px-8">
        <section
          className="not-found__panel bg-card border-border w-full max-w-xl rounded-lg border p-8 text-center shadow-sm sm:p-12"
          data-testid="not-found__panel"
        >
          <div className="not-found__icon-wrap bg-primary/10 mx-auto flex size-16 items-center justify-center rounded-full">
            <FileQuestion className="text-primary size-8" aria-hidden="true" />
          </div>

          <p
            className="not-found__status text-muted-foreground mt-6 text-sm font-medium tracking-wider uppercase"
            data-testid="not-found__status"
          >
            Error 404
          </p>

          <h1
            className="not-found__title text-foreground mt-2 text-3xl font-semibold tracking-tight sm:text-4xl"
            data-testid="not-found__title"
          >
            Page not found
          </h1>

          <p
            className="not-found__description text-muted-foreground mx-auto mt-4 max-w-md text-sm sm:text-base"
            data-testid="not-found__description"
          >
            The page you&apos;re looking for doesn&apos;t exist or has been moved. Check the URL or
            return to a known location.
          </p>

          <div className="not-found__actions mt-8 flex flex-col items-center justify-center gap-3 sm:flex-row">
            <Link
              href="/"
              className={cn(buttonVariants({ size: "lg" }), "not-found__home-link")}
              data-icon="inline-start"
              title="Return to home"
              aria-label="Home"
              data-testid="not-found__home-link"
            >
              <Home className="size-4" aria-hidden="true" />
              Return home
            </Link>
            <Link
              href="/backoffice"
              className={cn(
                buttonVariants({ variant: "outline", size: "lg" }),
                "not-found__backoffice-link",
              )}
              title="Go to BackOffice"
              aria-label="BackOffice"
              data-testid="not-found__backoffice-link"
            >
              Go to BackOffice
            </Link>
          </div>
        </section>
      </main>
    </div>
  );
}
