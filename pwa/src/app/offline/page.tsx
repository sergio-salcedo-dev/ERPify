import Link from "next/link";
import { Home, ShieldCheck, WifiOff } from "lucide-react";
import { buttonVariants } from "@/components/ui/button";
import { cn } from "@/lib/utils";

export const metadata = {
  title: "Offline · Erpify",
  description: "You are currently offline. Reconnect to continue using Erpify.",
};

export default function OfflinePage() {
  return (
    <div className="offline bg-background flex min-h-screen flex-col" data-testid="offline">
      <header className="offline__header border-border bg-card border-b">
        <div className="offline__header-inner mx-auto flex max-w-7xl items-center gap-2 px-4 py-4 sm:px-6 lg:px-8">
          <div className="offline__logo-mark bg-primary rounded-lg p-2">
            <ShieldCheck className="text-primary-foreground size-5" aria-hidden="true" />
          </div>
          <span className="offline__logo-text text-foreground text-lg font-semibold tracking-tight">
            Erpify
          </span>
        </div>
      </header>

      <main className="offline__main flex flex-1 items-center justify-center px-4 py-16 sm:px-6 lg:px-8">
        <section
          className="offline__panel bg-card border-border w-full max-w-xl rounded-lg border p-8 text-center shadow-sm sm:p-12"
          data-testid="offline__panel"
        >
          <div className="offline__icon-wrap bg-muted mx-auto flex size-16 items-center justify-center rounded-full">
            <WifiOff className="text-muted-foreground size-8" aria-hidden="true" />
          </div>

          <p
            className="offline__status text-muted-foreground mt-6 text-sm font-medium tracking-wider uppercase"
            data-testid="offline__status"
          >
            No connection
          </p>

          <h1
            className="offline__title text-foreground mt-2 text-3xl font-semibold tracking-tight sm:text-4xl"
            data-testid="offline__title"
          >
            You&apos;re offline
          </h1>

          <p
            className="offline__description text-muted-foreground mx-auto mt-4 max-w-md text-sm sm:text-base"
            data-testid="offline__description"
          >
            Erpify could not reach the network. Check your connection and try again — any work in
            progress was saved locally and will sync when you&apos;re back online.
          </p>

          <div className="offline__actions mt-8 flex flex-col items-center justify-center gap-3 sm:flex-row">
            <Link
              href="/"
              className={cn(buttonVariants({ size: "lg" }), "offline__home-link")}
              data-icon="inline-start"
              title="Return to home"
              aria-label="Home"
              data-testid="offline__home-link"
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
