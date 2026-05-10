import { WifiOff } from "lucide-react";
import { ErrorActions, ErrorScreen } from "@/context/shared/error/infrastructure/ui";
import { IconTone } from "@/context/shared/error/domain/IconTone";

export const metadata = {
  title: "Offline · Erpify",
  description: "You are currently offline. Reconnect to continue using Erpify.",
};

export default function OfflinePage() {
  return (
    <ErrorScreen
      testIdPrefix="offline"
      status="No connection"
      title="You're offline"
      description="Erpify could not reach the network. Check your connection and try again — any work in progress was saved locally and will sync when you're back online."
      icon={WifiOff}
      iconTone={IconTone.MUTED}
      actions={<ErrorActions />}
    />
  );
}
