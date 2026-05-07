"use client";

import { useEffect, useState } from "react";
import Link from "next/link";
import { Plus } from "lucide-react";
import { container } from "@/context/shared/infrastructure/DependencyInjection/Container";
import { SearchBanks } from "@/context/backoffice/bank/application/SearchBanks";
import type { Bank } from "@/context/backoffice/bank/domain/Bank";
import { HttpError } from "@/context/shared/infrastructure/HttpClient/HttpError";
import type { ProblemDetails } from "@/context/shared/domain/ProblemDetails";
import { AsyncBoundary } from "@/components/erpify";
import { Button } from "@/components/ui/button";
import { BanksTable } from "./_components/BanksTable";

type State = "loading" | "empty" | "error" | "ready";

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

export default function BanksListPage() {
  const [state, setState] = useState<State>("loading");
  const [banks, setBanks] = useState<Bank[]>([]);
  const [nextCursor, setNextCursor] = useState<string | undefined>(undefined);
  const [problem, setProblem] = useState<ProblemDetails | null>(null);

  useEffect(() => {
    let cancelled = false;
    (async () => {
      try {
        const useCase = container.get<SearchBanks>("BackOfficeSearchBanks");
        const page = await useCase.run();
        if (cancelled) return;
        setBanks(page.banks);
        setNextCursor(page.nextCursor);
        setState(page.banks.length === 0 ? "empty" : "ready");
      } catch (err) {
        if (cancelled) return;
        setProblem(
          err instanceof HttpError
            ? err.problem
            : genericProblem(err instanceof Error ? err.message : "Unknown error"),
        );
        setState("error");
      }
    })();
    return () => {
      cancelled = true;
    };
  }, []);

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

      <AsyncBoundary
        state={state}
        data={banks}
        error={problem ?? undefined}
        emptyVariant="first-run"
        emptyHeading="No banks yet"
        emptyDescription="Create the first bank to get started."
        emptyAction={
          <Button render={<Link href="/backoffice/banks/new">Create your first bank</Link>} />
        }
      >
        {(rows) => (
          <>
            <BanksTable banks={rows} />
            {nextCursor ? (
              <p className="text-muted-foreground text-xs">
                More banks available. Pagination not yet implemented in the UI.
              </p>
            ) : null}
          </>
        )}
      </AsyncBoundary>
    </div>
  );
}
