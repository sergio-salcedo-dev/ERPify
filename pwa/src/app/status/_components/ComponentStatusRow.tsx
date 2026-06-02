import React from "react";
import { cn } from "@/lib/utils";
import { SystemStatus, componentStatusLabel } from "./systemStatus";

interface ComponentStatusRowProps {
  name: string;
  status: SystemStatus;
  testId?: string;
}

const DOT_CLASSNAME: Record<SystemStatus, string> = {
  [SystemStatus.CHECKING]: "bg-slate-400",
  [SystemStatus.OPERATIONAL]: "bg-emerald-500",
  [SystemStatus.DEGRADED]: "bg-amber-500",
  [SystemStatus.DISRUPTED]: "bg-rose-500",
};

const LABEL_CLASSNAME: Record<SystemStatus, string> = {
  [SystemStatus.CHECKING]: "text-slate-500",
  [SystemStatus.OPERATIONAL]: "text-emerald-700",
  [SystemStatus.DEGRADED]: "text-amber-700",
  [SystemStatus.DISRUPTED]: "text-rose-700",
};

export const ComponentStatusRow: React.FC<ComponentStatusRowProps> = ({ name, status, testId }) => {
  return (
    <div
      data-testid={testId}
      className="component-status-row flex items-center justify-between border-b border-slate-100 py-4 last:border-b-0"
    >
      <span className="component-status-row__name font-medium text-slate-700">{name}</span>
      <span
        className={cn(
          "component-status-row__status flex items-center gap-2 text-sm font-medium",
          LABEL_CLASSNAME[status],
        )}
      >
        <span
          className={cn("component-status-row__dot size-2 rounded-full", DOT_CLASSNAME[status])}
          aria-hidden="true"
        />
        {componentStatusLabel(status)}
      </span>
    </div>
  );
};
