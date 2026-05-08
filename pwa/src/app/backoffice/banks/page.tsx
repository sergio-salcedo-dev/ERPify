"use client";

import { useEffect, useMemo, useState } from "react";
import Link from "next/link";
import { Plus } from "lucide-react";
import { container } from "@/context/shared/infrastructure/DependencyInjection/Container";
import { SearchBanks } from "@/context/backoffice/bank/application/SearchBanks";
import type { Bank } from "@/context/backoffice/bank/domain/Bank";
import { HttpError } from "@/context/shared/infrastructure/HttpClient/HttpError";
import type { ProblemDetails } from "@/context/shared/domain/ProblemDetails";
import { AsyncBoundary } from "@/components/erpify";
import { Button, buttonVariants } from "@/components/ui/button";
import { cn } from "@/lib/utils";
import { BanksTable } from "./_components/BanksTable";
import { BanksFilters } from "./_components/BanksFilters";
import {
  EMPTY_FILTER,
  applyFilters,
  applySort,
  hasActiveFilter,
  type BanksFilter,
  type BanksSort,
} from "./_lib/banksFilterSort";

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
  const [filter, setFilter] = useState<BanksFilter>(EMPTY_FILTER);
  const [sort, setSort] = useState<BanksSort>(null);

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

  const visibleBanks = useMemo(
    () => applySort(applyFilters(banks, filter), sort),
    [banks, filter, sort],
  );

  const resetFilters = (): void => {
    setFilter(EMPTY_FILTER);
    setSort(null);
  };

  return (
    <div className="banks-list space-y-6">
      <header className="banks-list__header flex flex-col gap-1 md:flex-row md:items-center md:justify-between">
        <div>
          <h1 className="text-foreground text-2xl font-semibold tracking-tight">Banks</h1>
          <p className="text-muted-foreground mt-1 text-sm">
            Manage the banks available in the back office.
          </p>
        </div>
        <Link
          href="/backoffice/banks/new"
          className={cn(buttonVariants({ size: "sm" }))}
          data-icon="inline-start"
          data-testid="banks-list__new-button"
        >
          <Plus className="size-3.5" aria-hidden="true" />
          New bank
        </Link>
      </header>

      {state === "ready" ? (
        <BanksFilters
          filter={filter}
          onFilterChange={setFilter}
          onReset={resetFilters}
          resetDisabled={!hasActiveFilter(filter) && !sort}
        />
      ) : null}

      <AsyncBoundary
        state={state}
        data={banks}
        error={problem ?? undefined}
        emptyVariant="first-run"
        emptyHeading="No banks yet"
        emptyDescription="Create the first bank to get started."
        emptyAction={
          <Link href="/backoffice/banks/new" className={cn(buttonVariants())}>
            Create your first bank
          </Link>
        }
      >
        {() =>
          visibleBanks.length === 0 ? (
            <section
              className="banks-list__empty-filtered border-border rounded-md border p-8 text-center"
              data-testid="banks-list__empty-filtered"
            >
              <h2 className="text-foreground text-base font-medium">No banks match your filters</h2>
              <p className="text-muted-foreground mt-1 text-sm">
                Adjust the filters or clear them to see the full list.
              </p>
              <Button
                type="button"
                variant="outline"
                size="sm"
                className="mt-4"
                onClick={resetFilters}
                data-testid="banks-list__reset-filters"
              >
                Reset filters
              </Button>
            </section>
          ) : (
            <>
              <BanksTable banks={visibleBanks} sort={sort} onSortChange={setSort} />
              {nextCursor ? (
                <p className="text-muted-foreground text-xs">
                  More banks available. Filters and sort apply only to this page.
                </p>
              ) : null}
            </>
          )
        }
      </AsyncBoundary>
    </div>
  );
}
