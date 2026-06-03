import React from "react";
import { SystemStatus } from "@/lib/systemStatus";
import { StatusBannerView, type BannerTheme } from "@/components/status/StatusBannerView";

interface StatusBannerProps {
  status: SystemStatus;
  datetime: string | null;
  testId?: string;
}

const MARKETING_THEME: BannerTheme = {
  palette: {
    [SystemStatus.CHECKING]: ["text-slate-500", "bg-slate-50 border-slate-200 text-slate-700"],
    [SystemStatus.OPERATIONAL]: [
      "text-emerald-600",
      "bg-emerald-50 border-emerald-200 text-emerald-800",
    ],
    [SystemStatus.DEGRADED]: ["text-amber-600", "bg-amber-50 border-amber-200 text-amber-800"],
    [SystemStatus.DISRUPTED]: ["text-rose-600", "bg-rose-50 border-rose-200 text-rose-800"],
  },
  classPrefix: "status-banner",
  radiusClassName: "rounded-2xl",
  headlineClassName: "text-lg",
  sublineClassName: "opacity-80",
};

export const StatusBanner: React.FC<StatusBannerProps> = (props) => (
  <StatusBannerView {...props} theme={MARKETING_THEME} />
);
