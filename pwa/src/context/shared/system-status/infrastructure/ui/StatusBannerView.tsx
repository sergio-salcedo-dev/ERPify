import { AlertTriangle, CheckCircle2, Loader2, XCircle, type LucideIcon } from "lucide-react";
import { dateTimeProvider } from "@/context/shared/date-time-provider/infrastructure";
import { cn } from "@/components/cn";
import { SystemStatus, systemHeadline } from "@/context/shared/system-status/domain/SystemStatus";

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
  /**
   * Detail read out with the headline, for a surface whose parts the headline folds away.
   *
   * The banner announces the worst status among its components, so a change confined to any
   * other one is silent — visible in a row, absent from the audio. It rides inside this region
   * rather than in one of its own: a second live region would announce over this one, which is
   * the shape a per-row region already failed at.
   */
  detail?: string;
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
  detail,
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
        {detail ? (
          <p
            className={`${theme.classPrefix}__detail sr-only`}
            data-testid={testId ? `${testId}__detail` : undefined}
          >
            {detail}
          </p>
        ) : null}
      </div>
    </div>
  );
}
