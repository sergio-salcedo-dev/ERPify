"use client";

import Link from "next/link";
import { useRouter } from "next/navigation";
import { Pencil, Trash2 } from "lucide-react";
import type { Bank } from "@/context/backoffice/bank/domain/Bank";
import { DataTable } from "@/components/erpify";
import type { DataTableColumn, DataTableSort } from "@/components/erpify";
import { Button, buttonVariants } from "@/components/ui/button";
import { cn } from "@/lib/utils";
import { DeleteBankButton } from "./DeleteBankButton";

interface BanksTableProps {
  banks: Bank[];
  sort?: DataTableSort | null;
  onSortChange?: (sort: DataTableSort | null) => void;
  onBankDeleted?: (id: string) => void;
}

export function BanksTable({ banks, sort, onSortChange, onBankDeleted }: BanksTableProps) {
  const router = useRouter();

  const columns: DataTableColumn<Bank>[] = [
    {
      id: "shortName",
      header: "Short name",
      sortable: true,
      cell: (row) => row.shortName,
      className: "max-w-[8rem] truncate",
    },
    {
      id: "name",
      header: "Name",
      sortable: true,
      cell: (row) => row.name,
      className: "min-w-0",
    },
    {
      id: "createdAt",
      header: "Created",
      sortable: true,
      cell: (row) => new Date(row.createdAt).toLocaleString(),
      className: "banks-table__col--md hidden md:table-cell",
    },
    {
      id: "updatedAt",
      header: "Updated",
      cell: (row) => new Date(row.updatedAt).toLocaleString(),
      className: "banks-table__col--lg hidden lg:table-cell",
    },
    {
      id: "actions",
      header: "Actions",
      align: "right",
      className: "banks-table__col--actions w-[1%] whitespace-nowrap",
      cell: (row) => (
        <div
          className="banks-table__actions flex items-center justify-end gap-1"
          onClick={(event) => event.stopPropagation()}
          onKeyDown={(event) => event.stopPropagation()}
          role="presentation"
        >
          <Link
            href={`/backoffice/banks/${encodeURIComponent(row.id)}/edit`}
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
            stopPropagation
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
      ),
    },
  ];

  return (
    <div className="banks-table -mx-4 overflow-x-auto sm:mx-0">
      <DataTable
        columns={columns}
        data={banks}
        rowKey={(row) => row.id}
        caption="Backoffice banks"
        density="comfortable"
        sort={sort ?? undefined}
        onSortChange={onSortChange}
        onRowActivate={(row) => router.push(`/backoffice/banks/${encodeURIComponent(row.id)}`)}
        className="banks-table__inner"
      />
    </div>
  );
}
