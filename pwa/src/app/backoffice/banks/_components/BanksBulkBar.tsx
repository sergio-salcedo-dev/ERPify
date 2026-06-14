"use client";

import { ResourceBulkBar } from "@/components/erpify";

interface BanksBulkBarProps {
  count: number;
  /** Names of the selected banks, used to scope the confirm phrase. */
  names?: readonly string[];
  onClear: () => void;
  onConfirmDelete: () => void;
}

/** Single source for the Delete trigger's testid — the page focuses it after a bulk-error refresh. */
export const BULK_DELETE_TESTID = "banks-list__bulk-delete";

export function BanksBulkBar({
  count,
  names = [],
  onClear,
  onConfirmDelete,
}: Readonly<BanksBulkBarProps>) {
  return (
    <ResourceBulkBar
      count={count}
      names={names}
      onClear={onClear}
      onConfirmDelete={onConfirmDelete}
      entitySingular="bank"
      entityPlural="banks"
      testIdPrefix="banks-list"
      deleteTestId={BULK_DELETE_TESTID}
    />
  );
}
