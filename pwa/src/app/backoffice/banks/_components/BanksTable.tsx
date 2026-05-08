"use client";

import { useRouter } from "next/navigation";
import type { Bank } from "@/context/backoffice/bank/domain/Bank";
import { DataTable } from "@/components/erpify";
import type { DataTableColumn, DataTableSort } from "@/components/erpify";

const columns: DataTableColumn<Bank>[] = [
  { id: "shortName", header: "Short name", sortable: true, cell: (row) => row.shortName },
  { id: "name", header: "Name", sortable: true, cell: (row) => row.name },
  {
    id: "createdAt",
    header: "Created",
    sortable: true,
    cell: (row) => new Date(row.createdAt).toLocaleString(),
  },
  {
    id: "updatedAt",
    header: "Updated",
    cell: (row) => new Date(row.updatedAt).toLocaleString(),
  },
];

interface BanksTableProps {
  banks: Bank[];
  sort?: DataTableSort | null;
  onSortChange?: (sort: DataTableSort | null) => void;
}

export function BanksTable({ banks, sort, onSortChange }: BanksTableProps) {
  const router = useRouter();

  return (
    <DataTable
      columns={columns}
      data={banks}
      rowKey={(row) => row.id}
      caption="Backoffice banks"
      density="comfortable"
      sort={sort ?? undefined}
      onSortChange={onSortChange}
      onRowActivate={(row) => router.push(`/backoffice/banks/${encodeURIComponent(row.id)}`)}
    />
  );
}
