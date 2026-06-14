import { StatusBadge } from "@/components/erpify";
import type { Role } from "@/context/shared/access/domain/Role";
import { ROLE_LABEL } from "../_lib/userLabels";

export function RolesBadges({ roles, testId }: Readonly<{ roles: Role[]; testId?: string }>) {
  const shown = roles.slice(0, 2);
  const extra = roles.length - shown.length;
  return (
    <div className="flex flex-wrap items-center gap-1" data-testid={testId}>
      {shown.map((r) => (
        <StatusBadge key={r} variant="neutral" label={ROLE_LABEL[r]} />
      ))}
      {extra > 0 ? <StatusBadge variant="info" label={`+${extra}`} /> : null}
    </div>
  );
}
