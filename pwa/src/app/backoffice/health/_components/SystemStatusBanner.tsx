import React from "react";
import { SystemStatus } from "@/lib/systemStatus";
import { StatusBannerView, type BannerTheme } from "@/components/status/StatusBannerView";

interface SystemStatusBannerProps {
  status: SystemStatus;
  datetime: string | null;
  testId?: string;
}

const BACKOFFICE_THEME: BannerTheme = {
  palette: {
    [SystemStatus.CHECKING]: [
      "text-muted-foreground",
      "bg-muted/50 border-border text-muted-foreground",
    ],
    [SystemStatus.OPERATIONAL]: ["text-success", "bg-success/10 border-success/30 text-foreground"],
    [SystemStatus.DEGRADED]: ["text-warning", "bg-warning/10 border-warning/30 text-foreground"],
    [SystemStatus.DISRUPTED]: [
      "text-destructive",
      "bg-destructive/10 border-destructive/30 text-foreground",
    ],
  },
  classPrefix: "system-status-banner",
  radiusClassName: "rounded-lg",
  headlineClassName: "text-base",
  sublineClassName: "text-muted-foreground",
};

export const SystemStatusBanner: React.FC<SystemStatusBannerProps> = (props) => (
  <StatusBannerView {...props} theme={BACKOFFICE_THEME} />
);
