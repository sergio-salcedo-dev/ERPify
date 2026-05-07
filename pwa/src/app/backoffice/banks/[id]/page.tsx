import Link from "next/link";
import { ChevronLeft, Pencil } from "lucide-react";
import { container } from "@/context/shared/infrastructure/DependencyInjection/Container";
import { FindBank } from "@/context/backoffice/bank/application/FindBank";
import type { Bank } from "@/context/backoffice/bank/domain/Bank";
import { HttpError } from "@/context/shared/infrastructure/HttpClient/HttpError";
import type { ProblemDetails } from "@/context/shared/domain/ProblemDetails";
import { CorrelationIdChip, EmptyState, ProblemDisplay } from "@/components/erpify";
import { Button } from "@/components/ui/button";
import { DeleteBankButton } from "../_components/DeleteBankButton";

export const dynamic = "force-dynamic";

interface BankDetailPageProps {
  params: Promise<{ id: string }>;
}

async function loadBank(
  id: string,
): Promise<{ kind: "ok"; bank: Bank } | { kind: "error"; problem: ProblemDetails }> {
  try {
    const useCase = container.get<FindBank>("BackOfficeFindBank");
    const bank = await useCase.run(id);
    return { kind: "ok", bank };
  } catch (err) {
    if (err instanceof HttpError) {
      return { kind: "error", problem: err.problem };
    }
    throw err;
  }
}

export default async function BankDetailPage({ params }: BankDetailPageProps) {
  const { id } = await params;
  const result = await loadBank(id);

  if (result.kind === "error" && result.problem.status === 404) {
    return (
      <div className="banks-detail space-y-6">
        <BackLink />
        <EmptyState
          variant="first-run"
          heading="Bank not found"
          description="We could not find a bank with that id. It may have been deleted."
          action={
            <div className="flex flex-col items-center gap-2">
              <CorrelationIdChip id={result.problem["correlation-id"]} label="Error ID:" />
              <Button render={<Link href="/backoffice/banks">Back to banks</Link>} />
            </div>
          }
        />
      </div>
    );
  }

  if (result.kind === "error") {
    return (
      <div className="banks-detail space-y-6">
        <BackLink />
        <ProblemDisplay problem={result.problem} variant="panel" />
      </div>
    );
  }

  const { bank } = result;

  return (
    <div className="banks-detail space-y-6">
      <BackLink />

      <header className="banks-detail__header flex flex-col gap-2 md:flex-row md:items-start md:justify-between">
        <div>
          <h1 className="text-foreground text-2xl font-semibold tracking-tight">{bank.name}</h1>
          <p className="text-muted-foreground mt-1 text-sm" data-testid="banks-detail__shortname">
            {bank.shortName}
          </p>
        </div>
        <div className="flex items-center gap-2">
          <Button
            variant="outline"
            size="sm"
            data-icon="inline-start"
            render={
              <Link
                href={`/backoffice/banks/${bank.id}/edit`}
                data-testid="banks-detail__edit-button"
              >
                <Pencil className="size-3.5" aria-hidden="true" />
                Edit
              </Link>
            }
          />
          <DeleteBankButton id={bank.id} name={bank.name} />
        </div>
      </header>

      <dl className="banks-detail__meta border-border bg-card grid grid-cols-1 gap-4 rounded-lg border p-4 md:grid-cols-2">
        <Field label="Name" value={bank.name} />
        <Field label="Short name" value={bank.shortName} />
        <Field label="Created" value={new Date(bank.createdAt).toLocaleString()} />
        <Field label="Updated" value={new Date(bank.updatedAt).toLocaleString()} />
      </dl>
    </div>
  );
}

function BackLink() {
  return (
    <Link
      href="/backoffice/banks"
      className="text-muted-foreground hover:text-foreground inline-flex items-center gap-1 text-xs"
    >
      <ChevronLeft className="size-3" aria-hidden="true" />
      Back to banks
    </Link>
  );
}

function Field({ label, value }: { label: string; value: string }) {
  return (
    <div className="banks-detail__field">
      <dt className="text-muted-foreground text-xs font-medium uppercase tracking-wide">{label}</dt>
      <dd className="text-foreground mt-1 text-sm">{value}</dd>
    </div>
  );
}
