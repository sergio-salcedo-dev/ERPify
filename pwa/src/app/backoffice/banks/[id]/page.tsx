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
import { CopyBankIdButton } from "../_components/CopyBankIdButton";
import { DeleteBankButton } from "../_components/DeleteBankButton";
import { formatBankDateTime } from "../_lib/formatDate";

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
    <div className="banks-detail mx-auto w-full max-w-screen-2xl space-y-4 sm:space-y-6 2xl:max-w-[120rem]">
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
          <header className="banks-detail__header flex flex-col gap-3 sm:flex-row sm:items-start sm:justify-between">
            <div className="min-w-0">
              <h1 className="text-foreground text-xl font-semibold tracking-tight break-words sm:text-2xl">
                {bank.name}
              </h1>
              <p
                className="text-muted-foreground mt-1 text-sm break-words"
                data-testid="banks-detail__shortname"
              >
                {bank.shortName}
              </p>
            </div>
            <div className="flex flex-wrap items-center gap-2 sm:flex-nowrap">
              <CopyBankIdButton id={bank.id} />
              <Link
                href={`/backoffice/banks/${encodeURIComponent(bank.id)}/edit`}
                className={cn(buttonVariants({ variant: "outline", size: "sm" }))}
                data-icon="inline-start"
                data-testid="banks-detail__edit-button"
                aria-label={`Edit bank ${bank.name}`}
                title={`Edit bank ${bank.name}`}
              >
                <Pencil className="size-3.5" aria-hidden="true" />
                Edit
              </Link>
              <DeleteBankButton id={bank.id} name={bank.name} />
            </div>
          </header>

          <dl className="banks-detail__meta border-border bg-card grid grid-cols-1 gap-4 rounded-lg border p-4 sm:grid-cols-2 xl:grid-cols-4">
            <Field label="Name" value={bank.name} />
            <Field label="Short name" value={bank.shortName} />
            <Field label="Created" value={formatBankDateTime(bank.createdAt)} />
            <Field label="Updated" value={formatBankDateTime(bank.updatedAt)} />
            <Field
              label="ID"
              value={bank.id}
              valueClassName="banks-detail__id break-all font-mono text-xs"
              testId="banks-detail__id"
            />
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
      aria-label="Back to banks"
      title="Back to banks"
    >
      <ChevronLeft className="size-3" aria-hidden="true" />
      Back to banks
    </Link>
  );
}

function Field({
  label,
  value,
  valueClassName,
  testId,
}: {
  label: string;
  value: string;
  valueClassName?: string;
  testId?: string;
}) {
  return (
    <div className="banks-detail__field">
      <dt className="text-muted-foreground text-xs font-medium uppercase tracking-wide">{label}</dt>
      <dd className={cn("text-foreground mt-1 text-sm", valueClassName)} data-testid={testId}>
        {value}
      </dd>
    </div>
  );
}
