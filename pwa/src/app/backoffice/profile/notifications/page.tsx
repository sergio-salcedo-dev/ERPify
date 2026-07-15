import type { Metadata } from "next";

export const metadata: Metadata = {
  title: "Notifications",
  robots: { index: false, follow: false },
};

/**
 * User-profile notifications surface. Reachable from the "User Profile" menu
 * group; the back-office layout already wraps every child in `RequireAuth`, so
 * this page inherits the auth guard. Title-only for now — the content lands in a
 * follow-up.
 */
export default function NotificationsPage() {
  return (
    <div className="account-notifications flex flex-col gap-6">
      <header
        className="account-notifications__hero flex flex-col gap-2"
        data-testid="account-notifications__hero"
      >
        <h1
          className="text-foreground text-2xl font-semibold tracking-tight"
          data-testid="account-notifications__title"
        >
          Notifications
        </h1>
      </header>
    </div>
  );
}
