import { Wrench } from "lucide-react";
import { ErrorScreen } from "@/context/shared/error/infrastructure/ui";
import { IconTone } from "@/context/shared/error/domain/IconTone";
import { MaintenanceActions } from "./_components/MaintenanceActions";

export const metadata = {
  title: "Service unavailable · Erpify",
  description: "Erpify is temporarily unavailable, for maintenance or an outage.",
};

export default function MaintenancePage() {
  return (
    <ErrorScreen
      testIdPrefix="maintenance"
      status="Error 503"
      title="Service temporarily unavailable"
      description="Erpify can't be reached right now, for maintenance or a temporary outage. We'll be back shortly — thank you for your patience."
      icon={Wrench}
      iconTone={IconTone.WARNING}
      actions={<MaintenanceActions />}
    />
  );
}
