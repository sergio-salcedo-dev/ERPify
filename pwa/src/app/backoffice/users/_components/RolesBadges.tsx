import { StatusBadge } from "@/components/erpify";
import type { Role } from "@/context/shared/access/domain/Role";

const ROLE_LABEL: Record<string, string> = {
  SUPER_ADMIN: "Super Admin",
  ADMIN: "Admin",
  EMPLOYEE: "Employee",
  CUSTOMER: "Customer",
  SUPPLIER: "Supplier",
};
export function RolesBadges({ roles, testId }: Readonly<{ roles: Role[]; testId?: string }>) {
  const shown = roles.slice(0, 2);
  const extra = roles.length - shown.length;
  return (
    <div className="flex flex-wrap items-center gap-1" data-testid={testId}>
      {shown.map((r) => (
        <StatusBadge key={r} variant="neutral" label={ROLE_LABEL[r] ?? r} />
      ))}
      {extra > 0 ? <StatusBadge variant="info" label={`+${extra}`} /> : null}
    </div>
  );
}
