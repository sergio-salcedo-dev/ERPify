"use client";

import { useEffect, useRef, useState } from "react";
import Link from "next/link";
import { useParams } from "next/navigation";
import { ChevronLeft } from "lucide-react";
import { container } from "@/context/shared/dependency-injection/infrastructure/Container";
import { FindBankAccount } from "@/context/backoffice/bankaccount/application/FindBankAccount";
import type { BankAccount } from "@/context/backoffice/bankaccount/domain/BankAccount";
import { HttpError } from "@/context/shared/http-client/domain/HttpError";
import type { ProblemDetails } from "@/context/shared/error/domain/ProblemDetails";
import { CorrelationIdChip, EmptyState, ProblemDisplay } from "@/components/erpify";
import { buttonVariants } from "@/components/ui/button-variants";
import { cn } from "@/components/cn";
import { uuidV7 } from "@/context/shared/uuid/infrastructure/uuidV7";
import { PersistenceAction, ViewStatus } from "@/context/shared/view-state/domain/ViewState";
import { HttpStatus } from "@/context/shared/http-client/domain/HttpStatus";
import { BankAccountForm } from "../../../banks/[id]/accounts/_components/BankAccountForm";
import { BankAccountStatusControl } from "../../../banks/[id]/accounts/_components/BankAccountStatusControl";
import { bankAccountRoutes } from "../../_lib/bankAccountRoutes";

type State = ViewStatus;

/** Single source — the form's stale-404 Refresh focuses this CTA once not-found lands. */
const BACK_TO_LIST_TESTID = "bank-account-edit__back-to-list";

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
 * Standalone edit for the cross-bank hub, keyed by account id alone — the
 * account owns its `bankId`, so no bank sits in the URL and there is no
 * foreign-bank guard (the nested `/banks/{id}/accounts/{id}/edit` still needs
 * one because every link there keys off the URL's bank). Cancel and a
 * successful save return to the global list, not under a bank.
 */
export default function StandaloneEditBankAccountPage() {
  const params = useParams<{ id: string }>();
  const accountId = params?.id ?? "";
  const [state, setState] = useState<State>(ViewStatus.LOADING);
  const [account, setAccount] = useState<BankAccount | null>(null);
  const [problem, setProblem] = useState<ProblemDetails | null>(null);
  // Bumped by the form's "Refresh" recovery action (stale 404). Re-runs the load
  // so an account deleted in parallel lands on the not-found empty state.
  const [reloadToken, setReloadToken] = useState(0);
  // Armed when the refresh is form-initiated: once the reload settles, focus the
  // "Back to bank accounts" CTA — or the page container when the landing has no
  // CTA — so unmounting the form never strands focus on <body>.
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
  }, [accountId, reloadToken]);

  useEffect(() => {
    if (!pendingRefreshFocusRef.current || state === ViewStatus.LOADING) return;
    pendingRefreshFocusRef.current = false;
    const backToList = globalThis.document.querySelector<HTMLElement>(
      `[data-testid='${BACK_TO_LIST_TESTID}']`,
    );
    (backToList ?? containerRef.current)?.focus();
  }, [state, account]);

  return (
    <div
      ref={containerRef}
      tabIndex={-1}
      className="bank-account-edit mx-auto w-full max-w-screen-md space-y-4 outline-none sm:space-y-6"
      data-testid="bank-account-edit"
      data-state={state}
    >
      <Link
        href={bankAccountRoutes.list}
        className="text-muted-foreground hover:text-foreground inline-flex items-center gap-1 text-xs"
        aria-label="Back to bank accounts"
        title="Back to bank accounts"
        data-testid="bank-account-edit__back-link"
      >
        <ChevronLeft className="size-3" aria-hidden="true" />
        Back to bank accounts
      </Link>

      {state === ViewStatus.LOADING ? (
        <p
          className="text-muted-foreground text-sm"
          role="status"
          aria-live="polite"
          data-testid="bank-account-edit__loading"
        >
          Loading account…
        </p>
      ) : null}

      {state === ViewStatus.NOT_FOUND && problem ? (
        <div data-testid="bank-account-edit__not-found">
          <EmptyState
            variant="first-run"
            heading="Account not found"
            description="We could not find an account with that id. It may have been deleted."
            action={
              <div className="flex flex-col items-center gap-2">
                <CorrelationIdChip id={problem["correlation-id"]} label="Error ID:" />
                <Link
                  href={bankAccountRoutes.list}
                  className={cn(buttonVariants())}
                  data-testid={BACK_TO_LIST_TESTID}
                >
                  Back to bank accounts
                </Link>
              </div>
            }
          />
        </div>
      ) : null}

      {state === ViewStatus.ERROR && problem ? (
        <div data-testid="bank-account-edit__error">
          <ProblemDisplay problem={problem} variant="panel" />
        </div>
      ) : null}

      {state === ViewStatus.READY && account ? (
        <>
          <header
            className="bank-account-edit__header space-y-1"
            data-testid="bank-account-edit__header"
          >
            <h1
              className="text-foreground text-xl font-semibold tracking-tight sm:text-2xl"
              data-testid="bank-account-edit__title"
            >
              Edit account
            </h1>
            <p
              className="text-muted-foreground text-sm break-words"
              data-testid="bank-account-edit__subtitle"
            >
              Update {account.holderName}.
            </p>
          </header>

          <BankAccountForm
            key={account.id}
            mode={PersistenceAction.UPDATING}
            bankId={account.bankId ?? ""}
            returnTo={bankAccountRoutes.list}
            initial={{
              id: account.id,
              holderName: account.holderName,
              iban: account.iban,
              bic: account.bic,
              alias: account.alias,
              currency: account.currency,
            }}
            onStaleAccount={() => {
              pendingRefreshFocusRef.current = true;
              setReloadToken((token) => token + 1);
            }}
          />

          <BankAccountStatusControl account={account} onChanged={setAccount} />
        </>
      ) : null}
    </div>
  );
}
