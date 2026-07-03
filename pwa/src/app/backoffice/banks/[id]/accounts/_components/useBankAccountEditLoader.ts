"use client";

import {
  useCallback,
  useEffect,
  useRef,
  useState,
  type Dispatch,
  type RefObject,
  type SetStateAction,
} from "react";
import { container } from "@/context/shared/dependency-injection/infrastructure/Container";
import { FindBankAccount } from "@/context/backoffice/bankaccount/application/FindBankAccount";
import type { BankAccount } from "@/context/backoffice/bankaccount/domain/BankAccount";
import { BankAccountProblemType } from "@/context/backoffice/bankaccount/domain/BankAccountProblemType";
import { HttpError } from "@/context/shared/http-client/domain/HttpError";
import { HttpStatus } from "@/context/shared/http-client/domain/HttpStatus";
import type { ProblemDetails } from "@/context/shared/error/domain/ProblemDetails";
import { uuidV7 } from "@/context/shared/uuid/infrastructure/uuidV7";
import { ViewStatus } from "@/context/shared/view-state/domain/ViewState";

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

/**
 * Client-side not-found problem for an account reached under the wrong bank's URL: the account API is
 * not bank-scoped, so it loads, but a foreign account is rejected as not-found without a server
 * round-trip. The not-found empty state requires a problem to render its correlation chip.
 */
function foreignBankProblem(): ProblemDetails {
  return {
    type: BankAccountProblemType.NOT_FOUND,
    title: "Account not found",
    status: HttpStatus.NOT_FOUND,
    detail: "We could not find an account with that id under this bank.",
    instance: uuidV7(),
    "correlation-id": uuidV7(),
  };
}

interface UseBankAccountEditLoaderParams {
  /** Account id from the route. */
  accountId: string;
  /**
   * When set, rejects an account whose `bankId` differs as not-found without a server round-trip —
   * the nested `/banks/{id}/accounts/{accountId}/edit` guard. Omit for the standalone hub, where no
   * bank sits in the URL and the account owns its own `bankId`.
   */
  expectedBankId?: string;
  /**
   * `data-testid` of the "back to list" CTA the form's stale-404 Refresh refocuses once the not-found
   * empty state lands, so unmounting the form never strands focus on `<body>`.
   */
  backToListTestId: string;
}

interface BankAccountEditLoader {
  state: ViewStatus;
  account: BankAccount | null;
  problem: ProblemDetails | null;
  setAccount: Dispatch<SetStateAction<BankAccount | null>>;
  containerRef: RefObject<HTMLDivElement | null>;
  /** Re-run the load and, once it settles, focus the back-to-list CTA (form-initiated stale-404 refresh). */
  requestRefresh: () => void;
}

/**
 * Shared load/error/reload/focus lifecycle for both bank-account edit surfaces — the standalone
 * cross-bank hub (`/bank-accounts/{id}/edit`) and the nested per-bank one
 * (`/banks/{id}/accounts/{accountId}/edit`). They differ only in the foreign-bank guard (opt-in via
 * `expectedBankId`) and their own JSX chrome (back-link copy/href, testids), which stays in each page.
 */
export function useBankAccountEditLoader({
  accountId,
  expectedBankId,
  backToListTestId,
}: UseBankAccountEditLoaderParams): BankAccountEditLoader {
  const [state, setState] = useState<ViewStatus>(ViewStatus.LOADING);
  const [account, setAccount] = useState<BankAccount | null>(null);
  const [problem, setProblem] = useState<ProblemDetails | null>(null);
  // Bumped by the form's "Refresh" recovery action (stale 404). Re-runs the load so an account
  // deleted in parallel lands on the not-found empty state.
  const [reloadToken, setReloadToken] = useState(0);
  // Armed when the refresh is form-initiated: once the reload settles, focus the back-to-list CTA —
  // or the page container when the landing has no CTA.
  const pendingRefreshFocusRef = useRef(false);
  const containerRef = useRef<HTMLDivElement | null>(null);

  // Reset state when the account id changes to avoid synchronous setState in useEffect.
  const [prevId, setPrevId] = useState(accountId);
  if (accountId !== prevId) {
    setPrevId(accountId);
    setState(ViewStatus.LOADING);
    setAccount(null);
    setProblem(null);
  }

  useEffect(() => {
    if (!accountId) return;
    let cancelled = false;
    (async () => {
      try {
        const useCase = container.get<FindBankAccount>("BackOfficeFindBankAccount");
        const result = await useCase.run(accountId);
        if (cancelled) return;
        // The account API is not bank-scoped, so an account reached under the wrong bank's URL still
        // loads. Reject the mismatch as not-found: every back-link, redirect, and the list realtime
        // scope key off the URL's bankId, so editing a foreign account would strand the user.
        if (
          expectedBankId !== undefined &&
          result.bankId !== undefined &&
          result.bankId !== expectedBankId
        ) {
          setProblem(foreignBankProblem());
          setState(ViewStatus.NOT_FOUND);
          return;
        }
        setAccount(result);
        setState(ViewStatus.READY);
      } catch (err) {
        if (cancelled) return;
        if (err instanceof HttpError) {
          setProblem(err.problem);
          setState(
            err.problem.status === HttpStatus.NOT_FOUND ? ViewStatus.NOT_FOUND : ViewStatus.ERROR,
          );
          return;
        }
        setProblem(genericProblem(err instanceof Error ? err.message : "Unknown error"));
        setState(ViewStatus.ERROR);
      }
    })();
    return () => {
      cancelled = true;
    };
  }, [accountId, expectedBankId, reloadToken]);

  useEffect(() => {
    if (!pendingRefreshFocusRef.current || state === ViewStatus.LOADING) return;
    pendingRefreshFocusRef.current = false;
    const backToList = globalThis.document.querySelector<HTMLElement>(
      `[data-testid='${backToListTestId}']`,
    );
    (backToList ?? containerRef.current)?.focus();
  }, [state, account, backToListTestId]);

  const requestRefresh = useCallback(() => {
    pendingRefreshFocusRef.current = true;
    setReloadToken((token) => token + 1);
  }, []);

  return { state, account, problem, setAccount, containerRef, requestRefresh };
}
