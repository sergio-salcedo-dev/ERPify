"use client";

import { useMemo } from "react";
import { useRouter } from "next/navigation";
import {
  DataTable,
  StatusBadge,
  TruncatedText,
  type DataTableColumn,
  type DataTableSelection,
  type DataTableSort,
  type ListDensity,
} from "@/components/erpify";
import type { ProblemDetails } from "@/context/shared/error/domain/ProblemDetails";
import type { BankAccountCollectionRow } from "@/context/backoffice/bankaccount/domain/BankAccountCollectionRow";
import { safeHref } from "@/context/shared/navigation/domain/safeHref";
import { IbanCell } from "../../banks/[id]/accounts/_components/IbanCell";
import { BankAccountRowActions } from "../../banks/[id]/accounts/_components/BankAccountRowActions";
import { bankAccountRoutes } from "../_lib/bankAccountRoutes";
import type { BankAccountColumnKey } from "../_lib/bankAccountColumns";
import { BANK_ACCOUNT_STATUS_LABEL, BANK_ACCOUNT_STATUS_VARIANT } from "../_lib/bankAccountStatus";
import { useIbanReveal } from "./useIbanReveal";

interface BankAccountsTableProps {
  accounts: BankAccountCollectionRow[];
  /** Which columns are visible (bank + holder + iban always rendered; rest toggle). */
  visible: BankAccountColumnKey[];
  sort?: DataTableSort | null;
  onSortChange?: (sort: DataTableSort | null) => void;
  onAccountDeleted?: (id: string) => void;
  onAccountDeleteFailed: (id: string, problem: ProblemDetails) => void;
  selection?: DataTableSelection;
  density?: ListDensity;
}

interface ColumnContext {
  visible: Set<BankAccountColumnKey>;
  revealedId: string | null;
  onReveal: (id: string) => void;
  onHide: () => void;
  onAccountDeleted?: (id: string) => void;
  onAccountDeleteFailed: (id: string, problem: ProblemDetails) => void;
}

const emptyCell = <span className="text-muted-foreground">—</span>;

// Built outside the component so the cell renderers are not re-declared on every
// render — the table's reveal state flows in as `ColumnContext`.
function buildColumns({
  visible,
  revealedId,
  onReveal,
  onHide,
  onAccountDeleted,
  onAccountDeleteFailed,
}: ColumnContext): DataTableColumn<BankAccountCollectionRow>[] {
  const columns: DataTableColumn<BankAccountCollectionRow>[] = [
    {
      id: "bank",
      header: "Bank",
      colClassName: "w-[20%]",
      className: "min-w-0",
      cell: (account) => (
        <div className="bank-accounts-list__bank min-w-0">
          <TruncatedText
            value={account.bankName}
            className="text-foreground text-sm font-medium"
            openOnRowFocus={false}
            testId={`bank-accounts-list__bank-${account.id}`}
          />
          <span className="text-muted-foreground block truncate font-mono text-xs uppercase">
            {account.bankShortName}
          </span>
        </div>
      ),
    },
    {
      id: "holderName",
      header: "Holder",
      sortable: true,
      colClassName: "w-[20%]",
      className: "min-w-0",
      cell: (account) => <TruncatedText value={account.holderName} openOnRowFocus />,
    },
    {
      id: "iban",
      header: "IBAN",
      colClassName: "w-[24%]",
      cell: (account) => (
        <IbanCell
          iban={account.iban}
          revealed={revealedId === account.id}
          onReveal={() => onReveal(account.id)}
          onHide={onHide}
          testId={`bank-accounts-list__iban-${account.id}`}
        />
      ),
    },
  ];

  if (visible.has("alias")) {
    columns.push({
      id: "alias",
      header: "Alias",
      colClassName: "w-[12%] max-lg:hidden",
      className: "banks-accounts-list__col--alias hidden lg:table-cell min-w-0",
      cell: (account) =>
        account.alias ? <TruncatedText value={account.alias} openOnRowFocus={false} /> : emptyCell,
    });
  }
  if (visible.has("bic")) {
    columns.push({
      id: "bic",
      header: "BIC",
      colClassName: "w-[12%] max-xl:hidden",
      className: "bank-accounts-list__col--bic hidden xl:table-cell",
      cell: (account) =>
        account.bic ? (
          <span className="font-mono text-xs uppercase">{account.bic}</span>
        ) : (
          emptyCell
        ),
    });
  }
  if (visible.has("currency")) {
    columns.push({
      id: "currency",
      header: "Currency",
      colClassName: "w-[10%] max-xl:hidden",
      className: "bank-accounts-list__col--currency hidden xl:table-cell",
      cell: (account) => <span className="font-mono">{account.currency}</span>,
    });
  }
  if (visible.has("status")) {
    columns.push({
      id: "status",
      header: "Status",
      colClassName: "w-24",
      className: "whitespace-nowrap",
      cell: (account) => (
        <StatusBadge
          variant={BANK_ACCOUNT_STATUS_VARIANT[account.status]}
          label={BANK_ACCOUNT_STATUS_LABEL[account.status]}
        />
      ),
    });
  }

  columns.push({
    id: "actions",
    header: "Actions",
    align: "right",
    colClassName: "w-28",
    className: "bank-accounts-list__col--actions",
    cell: (account) => (
      <BankAccountRowActions
        id={account.id}
        editHref={bankAccountRoutes.edit(account.id)}
        holderName={account.holderName}
        status={account.status}
        onAccountDeleted={onAccountDeleted}
        onAccountDeleteFailed={onAccountDeleteFailed}
        className="justify-end"
      />
    ),
  });

  return columns;
}

/**
 * Cross-bank accounts table: owning Bank, Holder, IBAN (masked, single-flight
 * reveal), plus toggleable Alias/BIC/Currency/Status and per-row actions. Rows
 * activate to the standalone detail page; the reveal resets whenever the page
 * (the `accounts` array) changes so paginating always re-masks (PII).
 */
export function BankAccountsTable({
  accounts,
  visible,
  sort,
  onSortChange,
  onAccountDeleted,
  onAccountDeleteFailed,
  selection,
  density = "compact",
}: Readonly<BankAccountsTableProps>) {
  const router = useRouter();
  const { revealedId, reveal, hide } = useIbanReveal(accounts);

  const columns = useMemo(
    () =>
      buildColumns({
        visible: new Set(visible),
        revealedId,
        onReveal: reveal,
        onHide: hide,
        onAccountDeleted,
        onAccountDeleteFailed,
      }),
    [visible, revealedId, reveal, hide, onAccountDeleted, onAccountDeleteFailed],
  );

  return (
    <div
      className="bank-accounts-list__table overflow-x-auto sm:mx-0 lg:overflow-x-visible"
      data-testid="bank-accounts-list__table"
    >
      <DataTable
        columns={columns}
        data={accounts}
        rowKey={(account) => account.id}
        caption="Bank accounts across all banks"
        density={density}
        sort={sort ?? undefined}
        onSortChange={onSortChange}
        selection={selection}
        onRowActivate={(account) => router.push(safeHref(bankAccountRoutes.detail(account.id)))}
        rowTestId={(account) => `bank-accounts-list__row-${account.id}`}
        testId="bank-accounts-list__table-inner"
        layout="fixed"
        className="bank-accounts-list__table-inner min-w-[52rem]"
      />
    </div>
  );
}
