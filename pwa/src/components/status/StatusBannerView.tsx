import { AlertTriangle, CheckCircle2, Loader2, XCircle, type LucideIcon } from "lucide-react";
import { dateTimeProvider } from "@/context/shared/infrastructure/DateTimeProvider";
import { cn } from "@/lib/utils";
import { SystemStatus, systemHeadline } from "@/lib/systemStatus";

const ICONS: Record<SystemStatus, LucideIcon> = {
  [SystemStatus.CHECKING]: Loader2,
  [SystemStatus.OPERATIONAL]: CheckCircle2,
  [SystemStatus.DEGRADED]: AlertTriangle,
  [SystemStatus.DISRUPTED]: XCircle,
};

/** Palette + layout for one design language; each status maps to `[iconClassName, containerClassName]`. */
export interface BannerTheme {
  palette: Record<SystemStatus, readonly [string, string]>;
  classPrefix: string;
  radiusClassName: string;
  headlineClassName: string;
  sublineClassName: string;
}

export interface StatusBannerViewProps {
  status: SystemStatus;
  datetime: string | null;
  theme: BannerTheme;
  testId?: string;
}

function subline(status: SystemStatus, datetime: string | null): string | null {
  if (status === SystemStatus.DISRUPTED) {
    return "We're having trouble reaching this service. Please try again shortly.";
  }
  if (datetime) {
    return `as of ${dateTimeProvider.formatIsoToLocalDateTime(datetime)}`;
  }
  return null;
}

export function StatusBannerView({
  status,
  datetime,
  theme,
  testId,
}: Readonly<StatusBannerViewProps>) {
  const Icon = ICONS[status];
  const [iconClassName, containerClassName] = theme.palette[status];
  const note = subline(status, datetime);

  return (
    <div
      role="status"
      aria-live="polite"
      aria-busy={status === SystemStatus.CHECKING}
      data-testid={testId}
      className={cn(
        `${theme.classPrefix} flex items-center gap-4 border p-6`,
        theme.radiusClassName,
        containerClassName,
      )}
    >
      <Icon
        className={cn(
          `${theme.classPrefix}__icon size-8 shrink-0`,
          iconClassName,
          status === SystemStatus.CHECKING && "animate-spin",
        )}
        aria-hidden="true"
      />
      <div className={`${theme.classPrefix}__text`}>
        <p className={cn(`${theme.classPrefix}__headline font-semibold`, theme.headlineClassName)}>
          {systemHeadline(status)}
        </p>
        {note ? (
          <p className={cn(`${theme.classPrefix}__subline text-sm`, theme.sublineClassName)}>
            {note}
          </p>
        ) : null}
      </div>
    </div>
  );
}
