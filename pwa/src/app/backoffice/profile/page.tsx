import type { Metadata } from "next";
import { ProfileView } from "./_components/ProfileView";

export const metadata: Metadata = {
  title: "My profile",
  robots: { index: false, follow: false },
};

/**
 * Landing page of the account area. The back-office layout already wraps every child
 * in `RequireAuth`, so this page inherits the auth guard and needs no permission gate:
 * every identity reads and changes its own account.
 */
export default function ProfilePage() {
  return <ProfileView />;
}
