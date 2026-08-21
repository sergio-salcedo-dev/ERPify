import { SystemStatus } from "@/context/shared/system-status/domain/SystemStatus";
import {
  StatusBannerView,
  type BannerTheme,
} from "@/context/shared/system-status/infrastructure/ui/StatusBannerView";

interface SystemStatusBannerProps {
  status: SystemStatus;
  datetime: string | null;
  testId?: string;
  detail?: string;
}

const BACKOFFICE_THEME: BannerTheme = {
  palette: {
    [SystemStatus.CHECKING]: [
      "text-muted-foreground",
      "bg-muted/50 border-border text-muted-foreground",
    ],
    [SystemStatus.OPERATIONAL]: [
      "text-success-strong",
      "bg-success/10 border-success/30 text-foreground",
    ],
    [SystemStatus.DEGRADED]: [
      "text-warning-strong",
      "bg-warning/10 border-warning/30 text-foreground",
    ],
    [SystemStatus.DISRUPTED]: [
      "text-danger-strong",
      "bg-destructive/10 border-destructive/30 text-foreground",
    ],
  },
  classPrefix: "system-status-banner",
  radiusClassName: "rounded-lg",
  headlineClassName: "text-base",
  sublineClassName: "text-muted-foreground",
};

export function SystemStatusBanner(props: Readonly<SystemStatusBannerProps>) {
  return <StatusBannerView {...props} theme={BACKOFFICE_THEME} />;
}
