"use client";

import { useMemo } from "react";
import Link from "next/link";
import { useRouter } from "next/navigation";
import { Pencil, Trash2 } from "lucide-react";
import type { Bank } from "@/context/backoffice/bank/domain/Bank";
import { CopyButton, DataTable, MonogramAvatar, StatusBadge } from "@/components/erpify";
import type { DataTableColumn, DataTableSort } from "@/components/erpify";
import { Button } from "@/components/ui/button";
import { buttonVariants } from "@/components/ui/button-variants";
import { cn } from "@/lib/utils";
import { dateTimeProvider } from "@/context/shared/infrastructure/DateTimeProvider";
import { safeHref } from "@/lib/safeHref";
import { isRecentlyCreated } from "../_lib/bankRecency";
import { bankRoutes } from "../_lib/bankRoutes";
import { DeleteBankButton } from "./DeleteBankButton";

interface BanksTableProps {
  banks: Bank[];
  sort?: DataTableSort | null;
  onSortChange?: (sort: DataTableSort | null) => void;
  onBankDeleted?: (id: string) => void;
}

interface BanksActionsCellProps {
  row: Bank;
  onBankDeleted?: (id: string) => void;
}

function BanksActionsCell({ row, onBankDeleted }: Readonly<BanksActionsCellProps>) {
  return (
    // Pure layout wrapper. <DataTable> already skips row activation when a
    // click / key lands on an in-row control, so no propagation handlers (and
    // therefore no interactive handlers on a non-interactive element) are
    // needed here.
    <div className="banks-table__actions flex items-center justify-end gap-1">
      <CopyButton
        value={row.id}
        iconOnly
        size="icon-sm"
        label="Copy ID"
        copiedLabel="ID copied"
        errorLabel="Copy failed"
        title={`Copy bank ${row.name} ID`}
        testId={`banks-table__copy-${row.id}`}
      />
      <Link
        href={safeHref(bankRoutes.edit(row.id))}
        className={cn(buttonVariants({ variant: "outline", size: "icon-sm" }))}
        aria-label="Edit"
        title={`Edit bank ${row.name}`}
        data-testid={`banks-table__edit-${row.id}`}
      >
        <Pencil className="size-3.5" aria-hidden="true" />
        <span className="sr-only">Edit</span>
      </Link>
      <DeleteBankButton
        id={row.id}
        name={row.name}
        triggerTestId={`banks-table__delete-${row.id}`}
        onDeleted={onBankDeleted}
        trigger={
          <Button
            variant="destructive"
            size="icon-sm"
            aria-label="Delete"
            title={`Delete bank ${row.name}`}
            data-testid={`banks-table__delete-${row.id}`}
          >
            <Trash2 className="size-3.5" aria-hidden="true" />
            <span className="sr-only">Delete</span>
          </Button>
        }
      />
    </div>
  );
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
        variant="info"
        label="New"
        className="banks-table__new flex-none"
        testId={`banks-table__new-${row.id}`}
      />
    ) : null}
  </div>
);

const renderRelativeCell = (iso: string, testId: string) => (
  <span title={dateTimeProvider.formatIsoToLocalDateTime(iso)} data-testid={testId}>
    {dateTimeProvider.formatIsoToRelative(iso)}
  </span>
);

const renderCreatedAtCell = (row: Bank) =>
  renderRelativeCell(row.createdAt, `banks-table__created-${row.id}`);
const renderUpdatedAtCell = (row: Bank) =>
  renderRelativeCell(row.updatedAt, `banks-table__updated-${row.id}`);

function buildBanksColumns(onBankDeleted?: (id: string) => void): DataTableColumn<Bank>[] {
  const renderActionsCell = (row: Bank) => (
    <BanksActionsCell row={row} onBankDeleted={onBankDeleted} />
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
        density="comfortable"
        sort={sort ?? undefined}
        onSortChange={onSortChange}
        onRowActivate={(row) => router.push(safeHref(bankRoutes.detail(row.id)))}
        rowTestId={(row) => `banks-table__row-${row.id}`}
        testId="banks-table__inner"
        className="banks-table__inner"
      />
    </div>
  );
}
