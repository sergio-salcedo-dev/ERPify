import { StatusBadge, type StatusBadgeVariant } from "@/components/erpify";
import {
  SystemStatus,
  componentStatusLabel,
} from "@/context/shared/system-status/domain/SystemStatus";

const BADGE_VARIANT: Record<SystemStatus, StatusBadgeVariant> = {
  [SystemStatus.CHECKING]: "neutral",
  [SystemStatus.OPERATIONAL]: "success",
  [SystemStatus.DEGRADED]: "warning",
  [SystemStatus.DISRUPTED]: "danger",
};

interface HealthComponentRowProps {
  name: string;
  status: SystemStatus;
  testId?: string;
}

export function HealthComponentRow({ name, status, testId }: Readonly<HealthComponentRowProps>) {
  return (
    <div
      data-testid={testId}
      className="health-component-row border-border flex items-center justify-between border-b py-4 last:border-b-0"
    >
      <span className="health-component-row__name text-foreground font-medium">{name}</span>
      <StatusBadge variant={BADGE_VARIANT[status]} label={componentStatusLabel(status)} />
    </div>
  );
}
