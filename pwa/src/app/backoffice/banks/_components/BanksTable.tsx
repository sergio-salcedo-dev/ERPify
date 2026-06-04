"use client";

import { useMemo } from "react";
import { useRouter } from "next/navigation";
import type { Bank } from "@/context/backoffice/bank/domain/Bank";
import { DataTable, StatusBadge, TruncatedText } from "@/components/erpify";
import type { DataTableColumn, DataTableSelection, DataTableSort } from "@/components/erpify";
import { dateTimeProvider } from "@/context/shared/infrastructure/DateTimeProvider";
import { safeHref } from "@/lib/safeHref";
import { isRecentlyCreated } from "../_lib/bankRecency";
import { bankRoutes } from "../_lib/bankRoutes";
import { BankRowActions } from "./BankRowActions";

interface BanksTableProps {
  banks: Bank[];
  sort?: DataTableSort | null;
  onSortChange?: (sort: DataTableSort | null) => void;
  onBankDeleted?: (id: string) => void;
  selection?: DataTableSelection;
  density?: "compact" | "comfortable";
}

const renderCodeCell = (row: Bank) => (
  <TruncatedText
    value={row.shortName}
    className="font-mono text-xs font-medium uppercase"
    openOnRowFocus={false}
  />
);

const renderNameCell = (row: Bank) => <TruncatedText value={row.name} />;

// Banks carry no domain status field; the list-level status is recency: a
// recently-created bank reads "New", everything else "Active". The badge
// lives in this dedicated column — never inline with the name.
const renderStatusCell = (row: Bank) =>
  isRecentlyCreated(row.createdAt, dateTimeProvider) ? (
    <StatusBadge variant="success" label="New" testId={`banks-table__new-${row.id}`} />
  ) : (
    <StatusBadge variant="neutral" label="Active" />
  );

const renderRelativeCell = (iso: string, testId: string) => (
  <span
    className="tabular-nums"
    title={dateTimeProvider.formatIsoToLocalDateTime(iso)}
    data-testid={testId}
  >
    {dateTimeProvider.formatIsoToRelative(iso)}
  </span>
);

const renderCreatedAtCell = (row: Bank) =>
  renderRelativeCell(row.createdAt, `banks-table__created-${row.id}`);
const renderUpdatedAtCell = (row: Bank) =>
  renderRelativeCell(row.updatedAt, `banks-table__updated-${row.id}`);

function buildBanksColumns(onBankDeleted?: (id: string) => void): DataTableColumn<Bank>[] {
  const renderActionsCell = (row: Bank) => (
    <BankRowActions
      id={row.id}
      name={row.name}
      surface="table"
      reveal="row"
      onBankDeleted={onBankDeleted}
      className="justify-end"
    />
  );
  return [
    {
      id: "shortName",
      header: "Code",
      sortable: true,
      cell: renderCodeCell,
      className: "min-w-0",
      colClassName: "w-28",
    },
    {
      id: "name",
      header: "Name",
      sortable: true,
      cell: renderNameCell,
      className: "min-w-0",
    },
    {
      id: "status",
      header: "Status",
      cell: renderStatusCell,
      className: "whitespace-nowrap",
      colClassName: "w-24",
    },
    {
      id: "updatedAt",
      header: "Updated",
      sortable: true,
      cell: renderUpdatedAtCell,
      className: "banks-table__col--lg hidden lg:table-cell",
      colClassName: "w-32 max-lg:hidden",
    },
    {
      id: "createdAt",
      header: "Created",
      sortable: true,
      cell: renderCreatedAtCell,
      className: "banks-table__col--xl hidden xl:table-cell",
      colClassName: "w-32 max-xl:hidden",
    },
    {
      id: "actions",
      header: "Actions",
      align: "right",
      className: "banks-table__col--actions",
      colClassName: "w-28",
      cell: renderActionsCell,
    },
  ];
}

export function BanksTable({
  banks,
  sort,
  onSortChange,
  onBankDeleted,
  selection,
  density = "compact",
}: Readonly<BanksTableProps>) {
  const router = useRouter();

  const columns = useMemo(() => buildBanksColumns(onBankDeleted), [onBankDeleted]);

  return (
    <div
      className="banks-table overflow-x-auto sm:mx-0 lg:overflow-x-visible"
      data-testid="banks-table"
    >
      <DataTable
        columns={columns}
        data={banks}
        rowKey={(row) => row.id}
        caption="Backoffice banks"
        density={density}
        sort={sort ?? undefined}
        onSortChange={onSortChange}
        selection={selection}
        onRowActivate={(row) => router.push(safeHref(bankRoutes.detail(row.id)))}
        rowTestId={(row) => `banks-table__row-${row.id}`}
        testId="banks-table__inner"
        layout="fixed"
        className="banks-table__inner min-w-[44rem]"
      />
    </div>
  );
}
