import { LogIn } from "lucide-react";
import { IconTone } from "@/context/shared/error/domain/IconTone";
import { ErrorActions } from "./ErrorActions";
import { ErrorScreen } from "./ErrorScreen";

/**
 * Branded UI for HTTP 401 — the user is not authenticated and must sign
 * in. Used in two places that must stay visually identical:
 *
 *  - `app/unauthorized.tsx` — the Next 15+ convention file rendered when
 *    a Server Component calls `unauthorized()` from `next/navigation`
 *    (Next maps `unauthorized()` to HTTP 401, despite the name).
 *  - `app/(errors)/unauthenticated/page.tsx` — the navigable info route
 *    at `/unauthenticated` that middleware / API redirects can target
 *    directly.
 *
 * Naming follows the UI semantic ("Sign in required") rather than HTTP's
 * historically misleading "Unauthorized" label, so the component reads
 * the same as it renders.
 */
export function SignInRequiredScreen() {
  return (
    <ErrorScreen
      testIdPrefix="unauthenticated"
      status="Error 401"
      title="Sign in required"
      description="Your session has expired or you are not signed in. Please sign in again to continue where you left off."
      icon={LogIn}
      iconTone={IconTone.PRIMARY}
      actions={<ErrorActions />}
    />
  );
}
