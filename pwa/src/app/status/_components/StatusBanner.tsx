import { SystemStatus } from "@/lib/systemStatus";
import { StatusBannerView, type BannerTheme } from "@/components/status/StatusBannerView";

interface StatusBannerProps {
  status: SystemStatus;
  datetime: string | null;
  testId?: string;
}

// Single, mode-agnostic theme: tints derive from semantic tokens (success /
// warning / destructive / muted) via an opacity modifier, so the banner reads
// correctly in both light and dark without a parallel dark palette.
const MARKETING_THEME: BannerTheme = {
  palette: {
    [SystemStatus.CHECKING]: [
      "text-muted-foreground",
      "bg-muted/50 border-border text-muted-foreground",
    ],
    [SystemStatus.OPERATIONAL]: ["text-success", "bg-success/10 border-success/30 text-foreground"],
    [SystemStatus.DEGRADED]: ["text-warning", "bg-warning/10 border-warning/30 text-foreground"],
    [SystemStatus.DISRUPTED]: [
      "text-danger-strong",
      "bg-destructive/10 border-destructive/30 text-foreground",
    ],
  },
  classPrefix: "status-banner",
  radiusClassName: "rounded-2xl",
  headlineClassName: "text-lg",
  sublineClassName: "text-muted-foreground",
};

export function StatusBanner(props: Readonly<StatusBannerProps>) {
  return <StatusBannerView {...props} theme={MARKETING_THEME} />;
}
