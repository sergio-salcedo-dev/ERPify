"use client";

import { ResourceColumnPicker } from "@/components/erpify";
import { TOGGLEABLE_COLUMNS, PINNED_COLUMNS, type BankColumnKey } from "../_lib/bankColumns";

const BANK_COLUMN_LABELS: Record<BankColumnKey, string> = {
  shortName: "Code",
  name: "Name",
  status: "Status",
  accounts: "Accounts",
  updatedAt: "Updated",
  createdAt: "Created",
};

/** Banks list column visibility picker — wires the shared picker to the bank column catalog. */
export function BanksColumnPicker({
  visible,
  onChange,
  testId,
}: Readonly<{
  visible: BankColumnKey[];
  onChange: (n: BankColumnKey[]) => void;
  testId?: string;
}>) {
  return (
    <ResourceColumnPicker
      visible={visible}
      onChange={onChange}
      labels={BANK_COLUMN_LABELS}
      pinned={PINNED_COLUMNS}
      toggleable={TOGGLEABLE_COLUMNS}
      itemTestIdPrefix="banks-columns"
      testId={testId}
    />
  );
}
