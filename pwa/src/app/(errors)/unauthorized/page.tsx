import { Lock } from "lucide-react";
import { ErrorActions, ErrorScreen } from "@/context/shared/error/infrastructure/ui";
import { IconTone } from "@/context/shared/error/domain/IconTone";

export const metadata = {
  title: "Unauthorized · Erpify",
  description: "You do not have permission to access this resource.",
};

export default function UnauthorizedPage() {
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
