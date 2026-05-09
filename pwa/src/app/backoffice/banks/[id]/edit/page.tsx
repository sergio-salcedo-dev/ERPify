"use client";

import { useEffect, useState } from "react";
import Link from "next/link";
import { useParams } from "next/navigation";
import { ChevronLeft } from "lucide-react";
import { container } from "@/context/shared/infrastructure/DependencyInjection/Container";
import { FindBank } from "@/context/backoffice/bank/application/FindBank";
import type { Bank } from "@/context/backoffice/bank/domain/Bank";
import { HttpError } from "@/context/shared/infrastructure/HttpClient/HttpError";
import type { ProblemDetails } from "@/context/shared/domain/ProblemDetails";
import { CorrelationIdChip, EmptyState, ProblemDisplay } from "@/components/erpify";
import { buttonVariants } from "@/components/ui/button";
import { cn } from "@/lib/utils";
import { BankForm } from "../../_components/BankForm";

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

export default function EditBankPage() {
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
    <div className="banks-edit mx-auto w-full max-w-screen-md space-y-4 sm:space-y-6">
      <BackLink id={id} />

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
          <header className="banks-edit__header space-y-1">
            <h1 className="text-foreground text-xl font-semibold tracking-tight sm:text-2xl">
              Edit bank
            </h1>
            <p className="text-muted-foreground text-sm break-words">Update {bank.name}.</p>
          </header>

          <BankForm
            key={bank.id}
            mode="edit"
            initial={{ id: bank.id, name: bank.name, shortName: bank.shortName }}
          />
        </>
      ) : null}
    </div>
  );
}

function BackLink({ id }: { id: string }) {
  return (
    <Link
      href={id ? `/backoffice/banks/${encodeURIComponent(id)}` : "/backoffice/banks"}
      className="text-muted-foreground hover:text-foreground inline-flex items-center gap-1 text-xs"
      aria-label="Back to bank detail"
      title="Back to bank detail"
    >
      <ChevronLeft className="size-3" aria-hidden="true" />
      Back to bank
    </Link>
  );
}
