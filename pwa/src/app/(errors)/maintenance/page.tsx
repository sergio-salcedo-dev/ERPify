import { CloudOff } from "lucide-react";
import { ErrorActions, ErrorScreen } from "@/context/shared/error/infrastructure/ui";
import { IconTone } from "@/context/shared/error/domain/IconTone";
import { RetryAfterOutageButton } from "./_components/RetryAfterOutageButton";

export const metadata = {
  title: "Service unavailable · Erpify",
  description: "Erpify cannot reach one of its services right now.",
};

/**
 * Where the auth guard sends a visitor when the server cannot tell whether they are signed in
 * (a 502/503/504 from `/me`). That is usually an unplanned outage rather than a planned window,
 * so the copy promises nothing about when it ends, and the retry returns to the interrupted
 * route carried in `?next=`.
 */
export default function MaintenancePage() {
  return (
    <ErrorScreen
      testIdPrefix="maintenance"
      status="Temporary outage"
      title="Service unavailable"
      description="Erpify cannot reach one of its services right now. This is usually brief — please try again in a moment."
      icon={CloudOff}
      iconTone={IconTone.WARNING}
      actions={
        <>
          <RetryAfterOutageButton />
          <ErrorActions primaryVariant="outline" />
        </>
      }
    />
  );
}
