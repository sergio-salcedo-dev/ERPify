"use client";

import { ResourceColumnPicker } from "@/components/erpify";
import {
  TOGGLEABLE_COLUMNS,
  PINNED_COLUMNS,
  type BankAccountColumnKey,
} from "../_lib/bankAccountColumns";

const BANK_ACCOUNT_COLUMN_LABELS: Record<BankAccountColumnKey, string> = {
  bank: "Bank",
  holderName: "Holder",
  iban: "IBAN",
  alias: "Alias",
  bic: "BIC",
  currency: "Currency",
  status: "Status",
};

/** Accounts list column visibility picker — wires the shared picker to the account column catalog. */
export function BankAccountsColumnPicker({
  visible,
  onChange,
  testId,
}: Readonly<{
  visible: BankAccountColumnKey[];
  onChange: (n: BankAccountColumnKey[]) => void;
  testId?: string;
}>) {
  return (
    <ResourceColumnPicker
      visible={visible}
      onChange={onChange}
      labels={BANK_ACCOUNT_COLUMN_LABELS}
      pinned={PINNED_COLUMNS}
      toggleable={TOGGLEABLE_COLUMNS}
      itemTestIdPrefix="bank-accounts-columns"
      testId={testId}
    />
  );
}
