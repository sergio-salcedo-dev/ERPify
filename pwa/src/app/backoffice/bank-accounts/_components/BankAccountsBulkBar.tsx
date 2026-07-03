"use client";

import { ResourceBulkBar } from "@/components/erpify";

interface BankAccountsBulkBarProps {
  count: number;
  /** Holder names of the selected accounts, used to scope the confirm phrase. */
  names?: readonly string[];
  onClear: () => void;
  onConfirmDelete: () => void;
}

/** Single source for the Delete trigger's testid — the page focuses it after a bulk-error refresh. */
export const BULK_DELETE_TESTID = "bank-accounts-list__bulk-delete";

export function BankAccountsBulkBar({
  count,
  names = [],
  onClear,
  onConfirmDelete,
}: Readonly<BankAccountsBulkBarProps>) {
  return (
    <ResourceBulkBar
      count={count}
      names={names}
      onClear={onClear}
      onConfirmDelete={onConfirmDelete}
      entitySingular="account"
      entityPlural="accounts"
      testIdPrefix="bank-accounts-list"
      deleteTestId={BULK_DELETE_TESTID}
    />
  );
}
