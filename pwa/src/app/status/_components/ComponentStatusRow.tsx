import { cn } from "@/lib/utils";
import { SystemStatus, componentStatusLabel } from "@/lib/systemStatus";

interface ComponentStatusRowProps {
  name: string;
  status: SystemStatus;
  testId?: string;
}

const DOT_CLASSNAME: Record<SystemStatus, string> = {
  [SystemStatus.CHECKING]: "bg-muted-foreground",
  [SystemStatus.OPERATIONAL]: "bg-success",
  [SystemStatus.DEGRADED]: "bg-warning",
  [SystemStatus.DISRUPTED]: "bg-destructive",
};

const LABEL_CLASSNAME: Record<SystemStatus, string> = {
  [SystemStatus.CHECKING]: "text-muted-foreground",
  [SystemStatus.OPERATIONAL]: "text-success",
  [SystemStatus.DEGRADED]: "text-warning",
  [SystemStatus.DISRUPTED]: "text-danger-strong",
};

export function ComponentStatusRow({ name, status, testId }: ComponentStatusRowProps) {
  return (
    <div
      data-testid={testId}
      className="component-status-row flex items-center justify-between border-b border-border py-4 last:border-b-0"
    >
      <span className="component-status-row__name font-medium text-foreground">{name}</span>
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
}
