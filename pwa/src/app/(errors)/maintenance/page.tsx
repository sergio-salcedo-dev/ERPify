import { Wrench } from "lucide-react";
import { ErrorActions, ErrorScreen } from "@/context/shared/error/infrastructure/ui";
import { IconTone } from "@/context/shared/error/domain/IconTone";

export const metadata = {
  title: "Scheduled maintenance · Erpify",
  description: "Erpify is currently undergoing scheduled maintenance.",
};

export default function MaintenancePage() {
  return (
    <ErrorScreen
      testIdPrefix="maintenance"
      status="Error 503"
      title="Scheduled maintenance"
      description="Erpify is temporarily offline. We'll be back shortly — thank you for your patience."
      icon={Wrench}
      iconTone={IconTone.WARNING}
      actions={<ErrorActions />}
    />
  );
}
