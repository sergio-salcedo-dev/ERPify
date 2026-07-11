import type { Metadata } from "next";
import { MySessions } from "@/context/shared/access/infrastructure/ui";

export const metadata: Metadata = {
  title: "Active sessions",
  robots: { index: false, follow: false },
};

/**
 * Account security surface listing the devices signed in to the current account. It mounts the shared
 * {@link MySessions} capability so the AC9 "my sessions" list is reachable from the back office; the backoffice
 * layout already wraps every child in `RequireAuth`, so this page inherits the auth guard.
 */
export default function SessionsPage() {
  return (
    <div className="account-sessions flex flex-col gap-6">
      <header
        className="account-sessions__hero flex flex-col gap-2"
        data-testid="account-sessions__hero"
      >
        <h1
          className="text-foreground text-2xl font-semibold tracking-tight"
          data-testid="account-sessions__title"
        >
          Sessions
        </h1>
        <p className="text-muted-foreground max-w-2xl text-sm leading-relaxed">
          The devices currently signed in to your account. Sign out the others if you don&apos;t
          recognise one.
        </p>
      </header>

      <MySessions />
    </div>
  );
}
