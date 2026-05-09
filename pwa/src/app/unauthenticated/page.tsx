import Link from "next/link";
import { Home, LogIn, ShieldCheck } from "lucide-react";
import { buttonVariants } from "@/components/ui/button";
import { cn } from "@/lib/utils";

export const metadata = {
  title: "Sign in required · Erpify",
  description: "You need to sign in to access this resource.",
};

export default function UnauthenticatedPage() {
  return (
    <div
      className="unauthenticated bg-background flex min-h-screen flex-col"
      data-testid="unauthenticated"
    >
      <header className="unauthenticated__header border-border bg-card border-b">
        <div className="unauthenticated__header-inner mx-auto flex max-w-7xl items-center gap-2 px-4 py-4 sm:px-6 lg:px-8">
          <div className="unauthenticated__logo-mark bg-primary rounded-lg p-2">
            <ShieldCheck className="text-primary-foreground size-5" aria-hidden="true" />
          </div>
          <span className="unauthenticated__logo-text text-foreground text-lg font-semibold tracking-tight">
            Erpify
          </span>
        </div>
      </header>

      <main className="unauthenticated__main flex flex-1 items-center justify-center px-4 py-16 sm:px-6 lg:px-8">
        <section
          className="unauthenticated__panel bg-card border-border w-full max-w-xl rounded-lg border p-8 text-center shadow-sm sm:p-12"
          data-testid="unauthenticated__panel"
        >
          <div className="unauthenticated__icon-wrap bg-primary/10 mx-auto flex size-16 items-center justify-center rounded-full">
            <LogIn className="text-primary size-8" aria-hidden="true" />
          </div>

          <p
            className="unauthenticated__status text-muted-foreground mt-6 text-sm font-medium tracking-wider uppercase"
            data-testid="unauthenticated__status"
          >
            Error 401
          </p>

          <h1
            className="unauthenticated__title text-foreground mt-2 text-3xl font-semibold tracking-tight sm:text-4xl"
            data-testid="unauthenticated__title"
          >
            Sign in required
          </h1>

          <p
            className="unauthenticated__description text-muted-foreground mx-auto mt-4 max-w-md text-sm sm:text-base"
            data-testid="unauthenticated__description"
          >
            Your session has expired or you are not signed in. Please sign in again to continue
            where you left off.
          </p>

          <div className="unauthenticated__actions mt-8 flex flex-col items-center justify-center gap-3 sm:flex-row">
            <Link
              href="/"
              className={cn(buttonVariants({ size: "lg" }), "unauthenticated__signin-link")}
              data-icon="inline-start"
              title="Sign in"
              aria-label="Sign in"
              data-testid="unauthenticated__signin-link"
            >
              <LogIn className="size-4" aria-hidden="true" />
              Sign in
            </Link>
            <Link
              href="/"
              className={cn(
                buttonVariants({ variant: "outline", size: "lg" }),
                "unauthenticated__home-link",
              )}
              data-icon="inline-start"
              title="Return to home"
              aria-label="Home"
              data-testid="unauthenticated__home-link"
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
