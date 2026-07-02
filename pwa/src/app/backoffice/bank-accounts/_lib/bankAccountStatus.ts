import type { StatusBadgeVariant } from "@/components/erpify";
import type { BankAccountStatus } from "@/context/backoffice/bankaccount/domain/BankAccount";

/**
 * Presentation maps for the account lifecycle status, keyed by the wire identity
 * value (`ACTIVE`/`INACTIVE`/`CLOSED`) the API emits — never a label. Shared by
 * every account list/detail surface so the badge colour + text can't drift.
 */
export const BANK_ACCOUNT_STATUS_VARIANT: Record<BankAccountStatus, StatusBadgeVariant> = {
  ACTIVE: "success",
  INACTIVE: "warning",
  CLOSED: "neutral",
};

export const BANK_ACCOUNT_STATUS_LABEL: Record<BankAccountStatus, string> = {
  ACTIVE: "Active",
  INACTIVE: "Inactive",
  CLOSED: "Closed",
};
