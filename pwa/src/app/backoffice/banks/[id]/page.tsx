"use client";

import { useEffect, useState } from "react";
import Link from "next/link";
import { useParams } from "next/navigation";
import { ChevronLeft, Pencil } from "lucide-react";
import { container } from "@/context/shared/infrastructure/DependencyInjection/Container";
import { FindBank } from "@/context/backoffice/bank/application/FindBank";
import type { Bank } from "@/context/backoffice/bank/domain/Bank";
import { HttpError } from "@/context/shared/infrastructure/HttpClient/HttpError";
import type { ProblemDetails } from "@/context/shared/domain/ProblemDetails";
import { CorrelationIdChip, EmptyState, ProblemDisplay } from "@/components/erpify";
import { buttonVariants } from "@/components/ui/button";
import { cn } from "@/lib/utils";
import { DeleteBankButton } from "../_components/DeleteBankButton";

type State = "loading" | "ready" | "not-found" | "error";

function genericProblem(detail: string): ProblemDetails {
  return {
    type: "about:blank",
    title: "Unexpected error",
    status: 0,
    detail,
    instance: crypto.randomUUID(),
    "correlation-id": crypto.randomUUID(),
  };
}

export default function BankDetailPage() {
  const params = useParams<{ id: string }>();
  const id = params?.id ?? "";
  const [state, setState] = useState<State>("loading");
  const [bank, setBank] = useState<Bank | null>(null);
  const [problem, setProblem] = useState<ProblemDetails | null>(null);

  useEffect(() => {
    if (!id) return;
    let cancelled = false;
    setState("loading");
    setBank(null);
    setProblem(null);
    (async () => {
      try {
        const useCase = container.get<FindBank>("BackOfficeFindBank");
        const result = await useCase.run(id);
        if (cancelled) return;
        setBank(result);
        setState("ready");
      } catch (err) {
        if (cancelled) return;
        if (err instanceof HttpError) {
          setProblem(err.problem);
          setState(err.problem.status === 404 ? "not-found" : "error");
          return;
        }
        setProblem(genericProblem(err instanceof Error ? err.message : "Unknown error"));
        setState("error");
      }
    })();
    return () => {
      cancelled = true;
    };
  }, [id]);

  return (
    <div className="banks-detail space-y-6">
      <BackLink />

      {state === "loading" ? (
        <p className="text-muted-foreground text-sm" role="status" aria-live="polite">
          Loading bank…
        </p>
      ) : null}

      {state === "not-found" && problem ? (
        <EmptyState
          variant="first-run"
          heading="Bank not found"
          description="We could not find a bank with that id. It may have been deleted."
          action={
            <div className="flex flex-col items-center gap-2">
              <CorrelationIdChip id={problem["correlation-id"]} label="Error ID:" />
              <Link href="/backoffice/banks" className={cn(buttonVariants())}>
                Back to banks
              </Link>
            </div>
          }
        />
      ) : null}

      {state === "error" && problem ? <ProblemDisplay problem={problem} variant="panel" /> : null}

      {state === "ready" && bank ? (
        <>
          <header className="banks-detail__header flex flex-col gap-2 md:flex-row md:items-start md:justify-between">
            <div>
              <h1 className="text-foreground text-2xl font-semibold tracking-tight">{bank.name}</h1>
              <p
                className="text-muted-foreground mt-1 text-sm"
                data-testid="banks-detail__shortname"
              >
                {bank.shortName}
              </p>
            </div>
            <div className="flex items-center gap-2">
              <Link
                href={`/backoffice/banks/${encodeURIComponent(bank.id)}/edit`}
                className={cn(buttonVariants({ variant: "outline", size: "sm" }))}
                data-icon="inline-start"
                data-testid="banks-detail__edit-button"
              >
                <Pencil className="size-3.5" aria-hidden="true" />
                Edit
              </Link>
              <DeleteBankButton id={bank.id} name={bank.name} />
            </div>
          </header>

          <dl className="banks-detail__meta border-border bg-card grid grid-cols-1 gap-4 rounded-lg border p-4 md:grid-cols-2">
            <Field label="Name" value={bank.name} />
            <Field label="Short name" value={bank.shortName} />
            <Field label="Created" value={new Date(bank.createdAt).toLocaleString()} />
            <Field label="Updated" value={new Date(bank.updatedAt).toLocaleString()} />
          </dl>
        </>
      ) : null}
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
