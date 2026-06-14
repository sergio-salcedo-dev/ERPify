"use client";

import { ResourceColumnPicker } from "@/components/erpify";
import { TOGGLEABLE_COLUMNS, PINNED_COLUMNS, type UserColumnKey } from "../_lib/userColumns";

const USER_COLUMN_LABELS: Record<UserColumnKey, string> = {
  email: "Email",
  roles: "Roles",
  status: "Status",
  createdAt: "Created",
  updatedAt: "Updated",
};

/** Users list column visibility picker — wires the shared picker to the user column catalog. */
export function UsersColumnPicker({
  visible,
  onChange,
  testId,
}: Readonly<{
  visible: UserColumnKey[];
  onChange: (n: UserColumnKey[]) => void;
  testId?: string;
}>) {
  return (
    <ResourceColumnPicker
      visible={visible}
      onChange={onChange}
      labels={USER_COLUMN_LABELS}
      pinned={PINNED_COLUMNS}
      toggleable={TOGGLEABLE_COLUMNS}
      itemTestIdPrefix="users-columns"
      testId={testId}
    />
  );
}
