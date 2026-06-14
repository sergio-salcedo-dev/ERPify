"use client";

import { useCallback, useState } from "react";
import {
  DataTable,
  StatusBadge,
  TruncatedText,
  type DataTableColumn,
  type ListDensity,
  type StatusBadgeVariant,
} from "@/components/erpify";
import type {
  BankAccount,
  BankAccountStatus,
} from "@/context/backoffice/bankaccount/domain/BankAccount";
import { IbanCell } from "./IbanCell";

const STATUS_VARIANT: Record<BankAccountStatus, StatusBadgeVariant> = {
  active: "success",
  inactive: "warning",
  closed: "neutral",
};

function statusLabel(status: BankAccountStatus): string {
  return status.charAt(0).toUpperCase() + status.slice(1);
}

interface BankAccountsTableProps {
  accounts: BankAccount[];
  density?: ListDensity;
}

interface ColumnContext {
  revealedId: string | null;
  onReveal: (id: string) => void;
  onHide: () => void;
}

// Built outside the component so the cell renderers are not re-declared as
// nested components on every render — the table's reveal state flows in as
// `ColumnContext`.
function buildColumns({
  revealedId,
  onReveal,
  onHide,
}: ColumnContext): DataTableColumn<BankAccount>[] {
  return [
    {
      id: "holder",
      header: "Holder",
      colClassName: "w-[26%]",
      cell: (account) => <TruncatedText value={account.holderName} openOnRowFocus />,
    },
    {
      id: "iban",
      header: "IBAN",
      colClassName: "w-[34%]",
      cell: (account) => (
        <IbanCell
          iban={account.iban}
          revealed={revealedId === account.id}
          onReveal={() => onReveal(account.id)}
          onHide={onHide}
          testId={`bank-accounts-table__iban-${account.id}`}
        />
      ),
    },
    {
      id: "alias",
      header: "Alias",
      colClassName: "w-[18%]",
      cell: (account) =>
        account.alias ? (
          <TruncatedText value={account.alias} openOnRowFocus={false} />
        ) : (
          <span className="text-muted-foreground">—</span>
        ),
    },
    {
      id: "currency",
      header: "Currency",
      colClassName: "w-[10%]",
      cell: (account) => <span className="font-mono">{account.currency}</span>,
    },
    {
      id: "status",
      header: "Status",
      colClassName: "w-[12%]",
      cell: (account) => (
        <StatusBadge variant={STATUS_VARIANT[account.status]} label={statusLabel(account.status)} />
      ),
    },
  ];
}

/**
 * Read-only table of a bank's associated accounts: Holder, IBAN (masked with a
 * per-row reveal), Alias, Currency, Status. Rows do not navigate (v1). The IBAN
 * reveal is single-flight — the table holds one `revealedId`, so revealing one
 * row re-masks the rest — and it resets whenever the page (the `accounts` array)
 * changes, so paginating always re-masks (PII).
 */
export function BankAccountsTable({ accounts, density }: Readonly<BankAccountsTableProps>) {
  const [revealedId, setRevealedId] = useState<string | null>(null);

  // A new page (or a reconcile) replaces the array — re-mask any revealed IBAN.
  // Adjusted during render (not an effect) so the re-mask is applied before the
  // new rows ever paint a stale revealed state.
  const [prevAccounts, setPrevAccounts] = useState(accounts);
  if (accounts !== prevAccounts) {
    setPrevAccounts(accounts);
    setRevealedId(null);
  }

  // Stable identity: IbanCell keys its ~10s auto-hide timer on `onHide`, so a
  // fresh closure each render would restart the countdown on every ancestor
  // re-render and keep the IBAN visible past the intended window.
  const hideRevealed = useCallback(() => setRevealedId(null), []);

  const columns = buildColumns({ revealedId, onReveal: setRevealedId, onHide: hideRevealed });

  return (
    <DataTable
      columns={columns}
      data={accounts}
      rowKey={(account) => account.id}
      rowTestId={(account) => `bank-accounts-table__row-${account.id}`}
      caption="Accounts associated with this bank"
      density={density}
      layout="fixed"
      testId="bank-accounts-table"
    />
  );
}
