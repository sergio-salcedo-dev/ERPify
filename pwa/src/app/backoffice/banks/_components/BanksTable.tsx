"use client";

import { useRouter } from "next/navigation";
import type { Bank } from "@/context/backoffice/bank/domain/Bank";
import { DataTable } from "@/components/erpify";
import type { DataTableColumn } from "@/components/erpify";

const columns: DataTableColumn<Bank>[] = [
  { id: "shortName", header: "Short name", cell: (row) => row.shortName },
  { id: "name", header: "Name", cell: (row) => row.name },
  {
    id: "updatedAt",
    header: "Updated",
    cell: (row) => new Date(row.updatedAt).toLocaleString(),
  },
];

interface BanksTableProps {
  banks: Bank[];
}

export function BanksTable({ banks }: BanksTableProps) {
  const router = useRouter();

  return (
    <DataTable
      columns={columns}
      data={banks}
      rowKey={(row) => row.id}
      caption="Backoffice banks"
      density="comfortable"
      onRowActivate={(row) => router.push(`/backoffice/banks/${encodeURIComponent(row.id)}`)}
    />
  );
}
