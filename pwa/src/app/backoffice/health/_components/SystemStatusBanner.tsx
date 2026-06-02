import React from "react";
import { AlertTriangle, CheckCircle2, Loader2, XCircle, type LucideIcon } from "lucide-react";
import { dateTimeProvider } from "@/context/shared/infrastructure/DateTimeProvider";
import { cn } from "@/lib/utils";
import { SystemStatus, systemHeadline } from "@/lib/systemStatus";

interface SystemStatusBannerProps {
  status: SystemStatus;
  datetime: string | null;
  testId?: string;
}

interface BannerStyle {
  icon: LucideIcon;
  iconClassName: string;
  containerClassName: string;
  spin?: boolean;
}

const BANNER_STYLES: Record<SystemStatus, BannerStyle> = {
  [SystemStatus.CHECKING]: {
    icon: Loader2,
    spin: true,
    iconClassName: "text-muted-foreground",
    containerClassName: "bg-muted/50 border-border text-muted-foreground",
  },
  [SystemStatus.OPERATIONAL]: {
    icon: CheckCircle2,
    iconClassName: "text-success",
    containerClassName: "bg-success/10 border-success/30 text-foreground",
  },
  [SystemStatus.DEGRADED]: {
    icon: AlertTriangle,
    iconClassName: "text-warning",
    containerClassName: "bg-warning/10 border-warning/30 text-foreground",
  },
  [SystemStatus.DISRUPTED]: {
    icon: XCircle,
    iconClassName: "text-destructive",
    containerClassName: "bg-destructive/10 border-destructive/30 text-foreground",
  },
};

function subline(status: SystemStatus, datetime: string | null): string | null {
  if (status === SystemStatus.DISRUPTED) {
    return "We're having trouble reaching this service. Please try again shortly.";
  }
  if (datetime) {
    return `as of ${dateTimeProvider.formatIsoToLocalDateTime(datetime)}`;
  }
  return null;
}

export const SystemStatusBanner: React.FC<SystemStatusBannerProps> = ({
  status,
  datetime,
  testId,
}) => {
  const style = BANNER_STYLES[status];
  const Icon = style.icon;
  const note = subline(status, datetime);

  return (
    <div
      role="status"
      aria-live="polite"
      aria-busy={status === SystemStatus.CHECKING}
      data-testid={testId}
      className={cn(
        "system-status-banner flex items-center gap-4 rounded-lg border p-6",
        style.containerClassName,
      )}
    >
      <Icon
        className={cn(
          "system-status-banner__icon size-8 shrink-0",
          style.iconClassName,
          style.spin && "animate-spin",
        )}
        aria-hidden="true"
      />
      <div className="system-status-banner__text">
        <p className="system-status-banner__headline text-base font-semibold">
          {systemHeadline(status)}
        </p>
        {note ? (
          <p className="system-status-banner__subline text-muted-foreground text-sm">{note}</p>
        ) : null}
      </div>
    </div>
  );
};
