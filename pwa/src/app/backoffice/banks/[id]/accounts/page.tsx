"use client";

import { useCallback, useEffect, useMemo, useRef, useState } from "react";
import Link from "next/link";
import { useParams } from "next/navigation";
import { ArrowLeft } from "lucide-react";
import { container } from "@/context/shared/infrastructure/DependencyInjection/Container";
import { SearchBankAccounts } from "@/context/backoffice/bankaccount/application/SearchBankAccounts";
import type { BankAccountSearchNavigator } from "@/context/backoffice/bankaccount/application/BankAccountSearchNavigator";
import type { BankAccount } from "@/context/backoffice/bankaccount/domain/BankAccount";
import { HttpError } from "@/context/shared/infrastructure/HttpClient/HttpError";
import type { ProblemDetails } from "@/context/shared/domain/ProblemDetails";
import type { PageEnvelope } from "@/context/shared/domain/Search";
import { HttpStatus } from "@/context/shared/domain/types/http";
import { ViewStatus } from "@/context/shared/domain/types/status";
import { AsyncBoundary } from "@/components/erpify";
import { Button } from "@/components/ui/button";
import { buttonVariants } from "@/components/ui/button-variants";
import { cn } from "@/lib/utils";
import { safeHref } from "@/lib/safeHref";
import { isUuid, uuidV7 } from "@/lib/uuidV7";
import { bankRoutes } from "../../_lib/bankRoutes";
import { BankAccountsTable } from "./_components/BankAccountsTable";
import { BankAccountsPagination } from "./_components/BankAccountsPagination";
import { BANK_ACCOUNTS_PAGE_SIZE_DEFAULT, type BankAccountsPageSize } from "./_lib/paginate";

type State = ViewStatus;

/** Synthetic problem for client-side failures with no server response. */
function genericProblem(detail: string): ProblemDetails {
  return {
    type: "about:blank",
    title: "Unexpected error",
    status: 0,
    detail,
    instance: uuidV7(),
    "correlation-id": uuidV7(),
  };
}

/** Client-side guard problem: a malformed bank id never reaches the network. */
function invalidUuidProblem(): ProblemDetails {
  return {
    type: "invalid-uuid",
    title: "Invalid bank reference",
    status: HttpStatus.BAD_REQUEST,
    detail: "The bank id in the URL is not a valid identifier.",
    instance: uuidV7(),
    "correlation-id": uuidV7(),
  };
}

export default function BankAccountsPage() {
  const params = useParams<{ id: string }>();
  const id = params?.id ?? "";

  const [state, setState] = useState<State>(ViewStatus.LOADING);
  const [accounts, setAccounts] = useState<BankAccount[]>([]);
  const [pagination, setPagination] = useState<PageEnvelope | null>(null);
  const [problem, setProblem] = useState<ProblemDetails | null>(null);
  const [pageSize, setPageSize] = useState<BankAccountsPageSize>(BANK_ACCOUNTS_PAGE_SIZE_DEFAULT);

  // The server-issued link that produced the current view, or null for the
  // first page (a plain query). A page step replays a server link verbatim; a
  // page-size change resets it to null (back to the first page). The link is an
  // opaque transport token — forwarded verbatim, never parsed.
  const [activeLink, setActiveLink] = useState<string | null>(null);
  // Monotonic request token: a slow in-flight response whose token is no longer
  // current is discarded, so a fast page click never lets an older result win.
  const seqRef = useRef(0);
  const mountedRef = useRef(true);
  useEffect(() => {
    mountedRef.current = true;
    return () => {
      mountedRef.current = false;
    };
  }, []);

  const loadAccounts = useCallback(async () => {
    const seq = ++seqRef.current;
    setState(ViewStatus.LOADING);
    setProblem(null);
    // A malformed id is a request-target error — never hit the network with it.
    if (!isUuid(id)) {
      if (!mountedRef.current || seq !== seqRef.current) return;
      setProblem(invalidUuidProblem());
      setState(ViewStatus.ERROR);
      return;
    }
    try {
      const result =
        activeLink === null
          ? await container
              .get<SearchBankAccounts>("BackOfficeSearchBankAccounts")
              .run(id, { filters: [], sort: null, limit: pageSize })
          : await container
              .get<BankAccountSearchNavigator>("BackOfficeBankAccountSearchNavigator")
              .follow(activeLink);
      if (!mountedRef.current || seq !== seqRef.current) return;
      setAccounts(result.accounts);
      setPagination({
        hasNext: result.hasNext,
        hasPrev: result.hasPrev,
        count: result.count,
        links: result.links,
      });
      setState(ViewStatus.READY);
    } catch (err) {
      if (!mountedRef.current || seq !== seqRef.current) return;
      const fallbackDetail = err instanceof Error ? err.message : "Unknown error";
      setProblem(err instanceof HttpError ? err.problem : genericProblem(fallbackDetail));
      setState(ViewStatus.ERROR);
    }
  }, [id, pageSize, activeLink]);

  useEffect(() => {
    // eslint-disable-next-line react-hooks/set-state-in-effect
    loadAccounts();
  }, [loadAccounts]);

  // Reset to the first page when the page size changes, adjusting state during
  // render so the stale cursor never reaches the wire.
  const [prevPageSize, setPrevPageSize] = useState(pageSize);
  if (pageSize !== prevPageSize) {
    setPrevPageSize(pageSize);
    setActiveLink(null);
  }

  const navigateTo = useCallback((link: string) => {
    setActiveLink(link);
  }, []);

  // An empty result with no navigation affordance reads as first-run empty; an
  // empty page that still offers a step stays READY so the controls render.
  const boundaryState = useMemo<State>(() => {
    if (state === ViewStatus.LOADING || state === ViewStatus.ERROR) return state;
    const hasNavAffordance = (pagination?.hasPrev ?? false) || (pagination?.hasNext ?? false);
    return accounts.length === 0 && !hasNavAffordance ? ViewStatus.EMPTY : ViewStatus.READY;
  }, [state, accounts.length, pagination]);

  return (
    <div
      className="bank-accounts mx-auto w-full max-w-[90rem] space-y-4 sm:space-y-6"
      data-testid="bank-accounts"
      data-state={boundaryState}
    >
      <header className="bank-accounts__header flex flex-col gap-3">
        <Link
          href={safeHref(bankRoutes.detail(id))}
          className={cn(
            buttonVariants({ variant: "ghost", size: "sm" }),
            "bank-accounts__back w-fit",
          )}
          data-icon="inline-start"
          data-testid="bank-accounts__back"
          aria-label="Back to bank"
          title="Back to bank"
        >
          <ArrowLeft className="size-3.5" aria-hidden="true" />
          Back to bank
        </Link>
        <div className="bank-accounts__heading min-w-0">
          <h1
            className="text-foreground text-xl font-semibold tracking-tight sm:text-2xl"
            data-testid="bank-accounts__title"
          >
            Associated accounts
          </h1>
          <p className="text-muted-foreground mt-1 text-sm" data-testid="bank-accounts__subtitle">
            Accounts linked to this bank.
          </p>
        </div>
      </header>

      <AsyncBoundary
        state={boundaryState}
        data={accounts}
        error={problem ?? undefined}
        errorAction={
          <Button
            type="button"
            variant="outline"
            size="sm"
            onClick={() => {
              loadAccounts();
            }}
            title="Retry loading accounts"
            aria-label="Retry loading accounts"
            data-testid="bank-accounts__retry"
          >
            Retry
          </Button>
        }
        emptyVariant="first-run"
        emptyHeading="No associated accounts"
        emptyDescription="This bank has no associated accounts."
      >
        {(data) => (
          <>
            <BankAccountsTable accounts={data} />
            <BankAccountsPagination
              pageSize={pageSize}
              hasPrev={pagination?.hasPrev ?? false}
              hasNext={pagination?.hasNext ?? false}
              onPrev={() => {
                const link = pagination?.links.prev;
                if (link) navigateTo(link);
              }}
              onNext={() => {
                const link = pagination?.links.next;
                if (link) navigateTo(link);
              }}
              onPageSizeChange={setPageSize}
            />
          </>
        )}
      </AsyncBoundary>
    </div>
  );
}
