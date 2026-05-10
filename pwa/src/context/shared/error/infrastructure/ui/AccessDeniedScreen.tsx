import { Lock } from "lucide-react";
import { IconTone } from "@/context/shared/error/domain/IconTone";
import { ErrorActions } from "./ErrorActions";
import { ErrorScreen } from "./ErrorScreen";

/**
 * Branded UI for HTTP 403 — authenticated but not authorised. Used in two
 * places that must stay visually identical:
 *
 *  - `app/forbidden.tsx` — the Next 15+ convention file rendered when a
 *    Server Component calls `forbidden()` from `next/navigation`.
 *  - `app/(errors)/unauthorized/page.tsx` — the navigable info route at
 *    `/unauthorized` that middleware / API redirects can target directly.
 *
 * Note on naming: HTTP 403 is "Forbidden", but the user-visible URL stays
 * `/unauthorized` because the project already exposed it that way before
 * the DDD extraction. The component name follows the UI semantic
 * ("Access denied"), not Next's convention name, so the file reads the
 * same as it renders.
 */
export function AccessDeniedScreen() {
  return (
    <ErrorScreen
      testIdPrefix="unauthorized"
      status="Error 403"
      title="Access denied"
      description="You do not have permission to view this resource. If you believe this is a mistake, contact your administrator to request the appropriate access."
      icon={Lock}
      iconTone={IconTone.WARNING}
      actions={<ErrorActions />}
    />
  );
}
