"use client";

import { ResourceBulkBar } from "@/components/erpify";

interface UsersBulkBarProps {
  count: number;
  /** Emails of the selected users, used to scope the confirm phrase. */
  names?: readonly string[];
  onClear: () => void;
  onConfirmDelete: () => void;
}

/** Single source for the Delete trigger's testid — the page focuses it after a bulk-error refresh. */
export const BULK_DELETE_TESTID = "users-list__bulk-delete";

export function UsersBulkBar({
  count,
  names = [],
  onClear,
  onConfirmDelete,
}: Readonly<UsersBulkBarProps>) {
  return (
    <ResourceBulkBar
      count={count}
      names={names}
      onClear={onClear}
      onConfirmDelete={onConfirmDelete}
      entitySingular="user"
      entityPlural="users"
      testIdPrefix="users-list"
      deleteTestId={BULK_DELETE_TESTID}
    />
  );
}
