import Link from "next/link";
import { ChevronLeft } from "lucide-react";
import { container } from "@/context/shared/infrastructure/DependencyInjection/Container";
import { FindBank } from "@/context/backoffice/bank/application/FindBank";
import type { Bank } from "@/context/backoffice/bank/domain/Bank";
import { HttpError } from "@/context/shared/infrastructure/HttpClient/HttpError";
import type { ProblemDetails } from "@/context/shared/domain/ProblemDetails";
import { CorrelationIdChip, EmptyState, ProblemDisplay } from "@/components/erpify";
import { Button } from "@/components/ui/button";
import { BankForm } from "../../_components/BankForm";

export const dynamic = "force-dynamic";

interface EditBankPageProps {
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

export default async function EditBankPage({ params }: EditBankPageProps) {
  const { id } = await params;
  const result = await loadBank(id);

  if (result.kind === "error" && result.problem.status === 404) {
    return (
      <div className="banks-edit space-y-6">
        <BackLink id={id} />
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
      <div className="banks-edit space-y-6">
        <BackLink id={id} />
        <ProblemDisplay problem={result.problem} variant="panel" />
      </div>
    );
  }

  const { bank } = result;

  return (
    <div className="banks-edit space-y-6">
      <BackLink id={id} />

      <header className="banks-edit__header space-y-1">
        <h1 className="text-foreground text-2xl font-semibold tracking-tight">Edit bank</h1>
        <p className="text-muted-foreground text-sm">Update {bank.name}.</p>
      </header>

      <BankForm mode="edit" initial={{ id: bank.id, name: bank.name, shortName: bank.shortName }} />
    </div>
  );
}

function BackLink({ id }: { id: string }) {
  return (
    <Link
      href={`/backoffice/banks/${id}`}
      className="text-muted-foreground hover:text-foreground inline-flex items-center gap-1 text-xs"
    >
      <ChevronLeft className="size-3" aria-hidden="true" />
      Back to bank
    </Link>
  );
}
