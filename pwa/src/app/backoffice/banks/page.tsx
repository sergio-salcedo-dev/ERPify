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
import { ViewStatus } from "@/context/shared/domain/types/status";
import { BanksTable } from "./_components/BanksTable";
import { BanksFilters } from "./_components/BanksFilters";
import { BanksPagination } from "./_components/BanksPagination";
import {
  DEFAULT_SORT,
  EMPTY_FILTER,
  applyFilters,
  applySort,
  hasActiveFilter,
  type BanksFilter,
  type BanksSort,
} from "./_lib/banksFilterSort";
import { BANKS_PAGE_SIZE_DEFAULT, type BanksPageSize, paginate } from "./_lib/paginate";

type State = ViewStatus;

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
  const [state, setState] = useState<State>(ViewStatus.LOADING);
  const [banks, setBanks] = useState<Bank[]>([]);
  const [nextCursor, setNextCursor] = useState<string | undefined>(undefined);
  const [problem, setProblem] = useState<ProblemDetails | null>(null);
  const [filter, setFilter] = useState<BanksFilter>(EMPTY_FILTER);
  const [sort, setSort] = useState<BanksSort>(DEFAULT_SORT);
  const [page, setPage] = useState(1);
  const [pageSize, setPageSize] = useState<BanksPageSize>(BANKS_PAGE_SIZE_DEFAULT);

  useEffect(() => {
    let cancelled = false;
    (async () => {
      try {
        const useCase = container.get<SearchBanks>("BackOfficeSearchBanks");
        const result = await useCase.run();
        if (cancelled) return;
        setBanks(result.banks);
        setNextCursor(result.nextCursor);
        setState(result.banks.length === 0 ? ViewStatus.EMPTY : ViewStatus.READY);
      } catch (err) {
        if (cancelled) return;
        setProblem(
          err instanceof HttpError
            ? err.problem
            : genericProblem(err instanceof Error ? err.message : "Unknown error"),
        );
        setState(ViewStatus.ERROR);
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

  useEffect(() => {
    setPage(1);
  }, [filter, sort, pageSize]);

  const paged = useMemo(
    () => paginate(visibleBanks, page, pageSize),
    [visibleBanks, page, pageSize],
  );

  const resetFilters = (): void => {
    setFilter(EMPTY_FILTER);
    setSort(DEFAULT_SORT);
  };

  const isDefaultSort =
    sort?.columnId === DEFAULT_SORT?.columnId && sort?.direction === DEFAULT_SORT?.direction;

  const handleBankDeleted = (id: string): void => {
    setBanks((prev) => prev.filter((bank) => bank.id !== id));
  };

  return (
    <div
      className="banks-list mx-auto w-full max-w-screen-2xl space-y-4 sm:space-y-6 2xl:max-w-[120rem]"
      data-testid="banks-list"
      data-state={state}
    >
      <header
        className="banks-list__header flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between"
        data-testid="banks-list__header"
      >
        <div className="banks-list__heading min-w-0">
          <h1
            className="text-foreground text-xl font-semibold tracking-tight sm:text-2xl"
            data-testid="banks-list__title"
          >
            Banks
          </h1>
          <p className="text-muted-foreground mt-1 text-sm" data-testid="banks-list__subtitle">
            Manage the banks available in the back office.
          </p>
          {state === ViewStatus.READY ? (
            <p
              className="banks-list__total text-muted-foreground mt-1 text-xs"
              data-testid="banks-list__total"
            >
              Total banks: <span className="text-foreground font-medium">{banks.length}</span>
            </p>
          ) : null}
        </div>
        <Link
          href="/backoffice/banks/new"
          className={cn(buttonVariants({ size: "sm" }), "banks-list__new-button w-full sm:w-auto")}
          data-icon="inline-start"
          data-testid="banks-list__new-button"
          aria-label="Create a new bank"
          title="Create a new bank"
        >
          <Plus className="size-3.5" aria-hidden="true" />
          New bank
        </Link>
      </header>

      {state === ViewStatus.READY ? (
        <BanksFilters
          filter={filter}
          onFilterChange={setFilter}
          onReset={resetFilters}
          resetDisabled={!hasActiveFilter(filter) && isDefaultSort}
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
          <Link
            href="/backoffice/banks/new"
            className={cn(buttonVariants())}
            aria-label="Create your first bank"
            title="Create your first bank"
          >
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
              <h2
                className="text-foreground text-base font-medium"
                data-testid="banks-list__empty-filtered-heading"
              >
                No banks match your filters
              </h2>
              <p
                className="text-muted-foreground mt-1 text-sm"
                data-testid="banks-list__empty-filtered-description"
              >
                Adjust the filters or clear them to see the full list.
              </p>
              <Button
                type="button"
                variant="outline"
                size="sm"
                className="mt-4"
                onClick={resetFilters}
                title="Clear all bank filters"
                aria-label="Clear all bank filters"
                data-testid="banks-list__reset-filters"
              >
                Reset filters
              </Button>
            </section>
          ) : (
            <>
              <BanksTable
                banks={paged.rows}
                sort={sort}
                onSortChange={setSort}
                onBankDeleted={handleBankDeleted}
              />
              <BanksPagination
                page={paged.page}
                pageSize={pageSize}
                hasPrev={paged.hasPrev}
                hasNext={paged.hasNext}
                onPageChange={setPage}
                onPageSizeChange={setPageSize}
              />
              {nextCursor ? (
                <p className="text-muted-foreground text-xs" data-testid="banks-list__more-notice">
                  More banks available. Filters, sort, and pagination apply only to this page.
                </p>
              ) : null}
            </>
          )
        }
      </AsyncBoundary>
    </div>
  );
}
