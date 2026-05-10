import { FileQuestion } from "lucide-react";
import { IconTone } from "@/context/shared/error/domain/IconTone";
import { ErrorActions } from "./ErrorActions";
import { ErrorScreen } from "./ErrorScreen";

/**
 * Branded UI for Next.js's `not-found.tsx` segment boundary (HTTP 404).
 *
 * The Next convention file at `app/not-found.tsx` is a thin re-export of
 * this component — keeping the JSX in the error module's
 * `infrastructure/ui` layer means the page layout, copy, icon and tone
 * have a single source of truth that's discoverable next to the rest of
 * the error surfaces (`<AccessDeniedScreen>`, `<SignInRequiredScreen>`, …).
 */
export function NotFoundScreen() {
  return (
    <ErrorScreen
      testIdPrefix="not-found"
      status="Error 404"
      title="Page not found"
      description="The page you're looking for doesn't exist or has been moved. Check the URL or return to a known location."
      icon={FileQuestion}
      iconTone={IconTone.PRIMARY}
      actions={<ErrorActions />}
    />
  );
}
