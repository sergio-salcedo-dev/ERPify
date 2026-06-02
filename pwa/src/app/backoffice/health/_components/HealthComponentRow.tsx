import React from "react";
import { StatusBadge } from "@/components/erpify";
import { SystemStatus, componentStatusLabel } from "@/lib/systemStatus";

type StatusBadgeVariant = "success" | "warning" | "danger" | "info" | "neutral";

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

export const HealthComponentRow: React.FC<HealthComponentRowProps> = ({ name, status, testId }) => {
  return (
    <div
      data-testid={testId}
      className="health-component-row border-border flex items-center justify-between border-b py-4 last:border-b-0"
    >
      <span className="health-component-row__name text-foreground font-medium">{name}</span>
      <StatusBadge variant={BADGE_VARIANT[status]} label={componentStatusLabel(status)} />
    </div>
  );
};
