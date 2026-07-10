import { StatusBadge, type StatusBadgeVariant } from "@/components/erpify";
import { UserStatus } from "@/context/shared/access/domain/UserStatus";

// Tones are semantic, never color-only (the label always rides along): ACTIVE is
// the only "good" state (success); SUSPENDED is a recoverable hold (warning);
// DEACTIVATED is an inert "off" state (neutral, not an app error); INVITED is
// informational — pending a first sign-in.
const VARIANT: Record<UserStatus, StatusBadgeVariant> = {
  [UserStatus.INVITED]: "info",
  [UserStatus.ACTIVE]: "success",
  [UserStatus.SUSPENDED]: "warning",
  [UserStatus.DEACTIVATED]: "neutral",
};
const LABEL: Record<UserStatus, string> = {
  [UserStatus.INVITED]: "Invited",
  [UserStatus.ACTIVE]: "Active",
  [UserStatus.SUSPENDED]: "Suspended",
  [UserStatus.DEACTIVATED]: "Deactivated",
};
export function UserStatusBadge({
  status,
  testId,
}: Readonly<{ status: UserStatus; testId?: string }>) {
  return <StatusBadge variant={VARIANT[status]} label={LABEL[status]} testId={testId} />;
}
