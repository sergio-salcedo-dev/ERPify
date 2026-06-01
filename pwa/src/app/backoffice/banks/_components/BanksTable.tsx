"use client";

import { useMemo } from "react";
import { useRouter } from "next/navigation";
import type { Bank } from "@/context/backoffice/bank/domain/Bank";
import { DataTable, MonogramAvatar, StatusBadge } from "@/components/erpify";
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
}

const renderShortNameCell = (row: Bank) => (
  <span className="block truncate font-mono text-xs uppercase" title={row.shortName}>
    {row.shortName}
  </span>
);

const renderNameCell = (row: Bank) => (
  <div className="banks-table__identity flex min-w-0 items-center gap-2.5">
    <MonogramAvatar name={row.name} testId={`banks-table__avatar-${row.id}`} />
    <span className="min-w-0 truncate">{row.name}</span>
    {isRecentlyCreated(row.createdAt, dateTimeProvider) ? (
      <StatusBadge
        variant="success"
        label="New"
        className="banks-table__new flex-none"
        testId={`banks-table__new-${row.id}`}
      />
    ) : null}
  </div>
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
      onBankDeleted={onBankDeleted}
      className="justify-end"
    />
  );
  return [
    {
      id: "shortName",
      header: "Short name",
      sortable: true,
      cell: renderShortNameCell,
      className: "max-w-[8rem] truncate",
    },
    {
      id: "name",
      header: "Name",
      sortable: true,
      cell: renderNameCell,
      className: "min-w-0",
    },
    {
      id: "createdAt",
      header: "Created",
      sortable: true,
      cell: renderCreatedAtCell,
      className: "banks-table__col--md hidden md:table-cell",
    },
    {
      id: "updatedAt",
      header: "Updated",
      sortable: true,
      cell: renderUpdatedAtCell,
      className: "banks-table__col--lg hidden lg:table-cell",
    },
    {
      id: "actions",
      header: "Actions",
      align: "right",
      className: "banks-table__col--actions w-[1%] whitespace-nowrap",
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
}: Readonly<BanksTableProps>) {
  const router = useRouter();

  const columns = useMemo(() => buildBanksColumns(onBankDeleted), [onBankDeleted]);

  return (
    <div className="banks-table overflow-x-auto sm:mx-0" data-testid="banks-table">
      <DataTable
        columns={columns}
        data={banks}
        rowKey={(row) => row.id}
        caption="Backoffice banks"
        density="compact"
        sort={sort ?? undefined}
        onSortChange={onSortChange}
        selection={selection}
        onRowActivate={(row) => router.push(safeHref(bankRoutes.detail(row.id)))}
        rowTestId={(row) => `banks-table__row-${row.id}`}
        testId="banks-table__inner"
        className="banks-table__inner"
      />
    </div>
  );
}
