import React from "react";
import { AlertTriangle, CheckCircle2, Loader2, XCircle, type LucideIcon } from "lucide-react";
import { dateTimeProvider } from "@/context/shared/infrastructure/DateTimeProvider";
import { cn } from "@/lib/utils";
import { SystemStatus, systemHeadline } from "@/lib/systemStatus";

interface StatusBannerProps {
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
    iconClassName: "text-slate-500",
    containerClassName: "bg-slate-50 border-slate-200 text-slate-700",
  },
  [SystemStatus.OPERATIONAL]: {
    icon: CheckCircle2,
    iconClassName: "text-emerald-600",
    containerClassName: "bg-emerald-50 border-emerald-200 text-emerald-800",
  },
  [SystemStatus.DEGRADED]: {
    icon: AlertTriangle,
    iconClassName: "text-amber-600",
    containerClassName: "bg-amber-50 border-amber-200 text-amber-800",
  },
  [SystemStatus.DISRUPTED]: {
    icon: XCircle,
    iconClassName: "text-rose-600",
    containerClassName: "bg-rose-50 border-rose-200 text-rose-800",
  },
};

function subline(status: SystemStatus, datetime: string | null): string | null {
  // datetime is irrelevant for DISRUPTED; the error copy takes precedence.
  if (status === SystemStatus.DISRUPTED) {
    return "We're having trouble reaching this service. Please try again shortly.";
  }
  if (datetime) {
    return `as of ${dateTimeProvider.formatIsoToLocalDateTime(datetime)}`;
  }
  return null;
}

export const StatusBanner: React.FC<StatusBannerProps> = ({ status, datetime, testId }) => {
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
        "status-banner flex items-center gap-4 rounded-2xl border p-6",
        style.containerClassName,
      )}
    >
      <Icon
        className={cn(
          "status-banner__icon size-8 shrink-0",
          style.iconClassName,
          style.spin && "animate-spin",
        )}
        aria-hidden="true"
      />
      <div className="status-banner__text">
        <p className="status-banner__headline text-lg font-semibold">{systemHeadline(status)}</p>
        {note ? <p className="status-banner__subline text-sm opacity-80">{note}</p> : null}
      </div>
    </div>
  );
};
