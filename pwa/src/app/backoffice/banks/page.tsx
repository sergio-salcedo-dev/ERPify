import Link from "next/link";
import { Plus } from "lucide-react";
import { container } from "@/context/shared/infrastructure/DependencyInjection/Container";
import { SearchBanks } from "@/context/backoffice/bank/application/SearchBanks";
import type { Bank } from "@/context/backoffice/bank/domain/Bank";
import { HttpError } from "@/context/shared/infrastructure/HttpClient/HttpError";
import type { ProblemDetails } from "@/context/shared/domain/ProblemDetails";
import { DataTable, EmptyState, ProblemDisplay } from "@/components/erpify";
import type { DataTableColumn } from "@/components/erpify";
import { Button } from "@/components/ui/button";

export const dynamic = "force-dynamic";

const columns: DataTableColumn<Bank>[] = [
  { id: "shortName", header: "Short name", cell: (row) => row.shortName },
  { id: "name", header: "Name", cell: (row) => row.name },
  {
    id: "updatedAt",
    header: "Updated",
    cell: (row) => new Date(row.updatedAt).toLocaleString(),
  },
  {
    id: "actions",
    header: "",
    align: "right",
    cell: (row) => (
      <Link
        href={`/backoffice/banks/${row.id}`}
        className="text-primary text-xs font-medium hover:underline"
        data-testid={`banks-list__view-${row.id}`}
      >
        View
      </Link>
    ),
  },
];

async function loadPage(): Promise<
  { kind: "ok"; banks: Bank[]; nextCursor?: string } | { kind: "error"; problem: ProblemDetails }
> {
  try {
    const useCase = container.get<SearchBanks>("BackOfficeSearchBanks");
    const page = await useCase.run();
    return { kind: "ok", banks: page.banks, nextCursor: page.nextCursor };
  } catch (err) {
    if (err instanceof HttpError) {
      return { kind: "error", problem: err.problem };
    }
    throw err;
  }
}

export default async function BanksListPage() {
  const result = await loadPage();

  return (
    <div className="banks-list space-y-6">
      <header className="banks-list__header flex flex-col gap-1 md:flex-row md:items-center md:justify-between">
        <div>
          <h1 className="text-foreground text-2xl font-semibold tracking-tight">Banks</h1>
          <p className="text-muted-foreground mt-1 text-sm">
            Manage the banks available in the back office.
          </p>
        </div>
        <Button
          size="sm"
          data-icon="inline-start"
          render={
            <Link href="/backoffice/banks/new" data-testid="banks-list__new-button">
              <Plus className="size-3.5" aria-hidden="true" />
              New bank
            </Link>
          }
        />
      </header>

      {result.kind === "error" ? (
        <ProblemDisplay problem={result.problem} variant="panel" />
      ) : result.banks.length === 0 ? (
        <EmptyState
          variant="first-run"
          heading="No banks yet"
          description="Create the first bank to get started."
          action={
            <Button render={<Link href="/backoffice/banks/new">Create your first bank</Link>} />
          }
        />
      ) : (
        <>
          <DataTable
            columns={columns}
            data={result.banks}
            rowKey={(row) => row.id}
            caption="Backoffice banks"
            density="comfortable"
          />
          {result.nextCursor ? (
            <p className="text-muted-foreground text-xs">
              More banks available. Pagination not yet implemented in the UI.
            </p>
          ) : null}
        </>
      )}
    </div>
  );
}
