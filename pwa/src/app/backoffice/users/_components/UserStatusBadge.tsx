import { StatusBadge } from "@/components/erpify";
import { UserStatus } from "@/context/shared/access/domain/UserStatus";

const VARIANT: Record<UserStatus, "success" | "warning" | "danger"> = {
  [UserStatus.ACTIVE]: "success",
  [UserStatus.PENDING]: "warning",
  [UserStatus.BLOCKED]: "danger",
};
const LABEL: Record<UserStatus, string> = {
  [UserStatus.ACTIVE]: "Active",
  [UserStatus.PENDING]: "Pending",
  [UserStatus.BLOCKED]: "Blocked",
};
export function UserStatusBadge({
  status,
  testId,
}: Readonly<{ status: UserStatus; testId?: string }>) {
  return <StatusBadge variant={VARIANT[status]} label={LABEL[status]} testId={testId} />;
}
