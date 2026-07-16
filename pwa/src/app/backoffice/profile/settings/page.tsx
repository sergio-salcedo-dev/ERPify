import type { Metadata } from "next";

export const metadata: Metadata = {
  title: "Settings",
  robots: { index: false, follow: false },
};

/**
 * User-profile settings surface. Reachable from the "User Profile" menu group;
 * the back-office layout already wraps every child in `RequireAuth`, so this
 * page inherits the auth guard. Title-only for now — the content lands in a
 * follow-up.
 */
export default function SettingsPage() {
  return (
    <div className="account-settings flex flex-col gap-6">
      <header
        className="account-settings__hero flex flex-col gap-2"
        data-testid="account-settings__hero"
      >
        <h1
          className="text-foreground text-2xl font-semibold tracking-tight"
          data-testid="account-settings__title"
        >
          Settings
        </h1>
      </header>
    </div>
  );
}
