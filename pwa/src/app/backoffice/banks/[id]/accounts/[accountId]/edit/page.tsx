"use client";

import Link from "next/link";
import { useParams } from "next/navigation";
import { ChevronLeft } from "lucide-react";
import { CorrelationIdChip, EmptyState, ProblemDisplay } from "@/components/erpify";
import { buttonVariants } from "@/components/ui/button-variants";
import { cn } from "@/components/cn";
import { safeHref } from "@/context/shared/navigation/domain/safeHref";
import { PersistenceAction, ViewStatus } from "@/context/shared/view-state/domain/ViewState";
import { bankAccountRoutes } from "../../_lib/bankAccountRoutes";
import { BankAccountForm } from "../../_components/BankAccountForm";
import { BankAccountStatusControl } from "../../_components/BankAccountStatusControl";
import { useBankAccountEditLoader } from "../../_components/useBankAccountEditLoader";

/** Single source — the form's stale-404 Refresh focuses this CTA once not-found lands. */
const BACK_TO_ACCOUNTS_TESTID = "bank-accounts-edit__back-to-accounts";

export default function EditBankAccountPage() {
  const params = useParams<{ id: string; accountId: string }>();
  const bankId = params?.id ?? "";
  const accountId = params?.accountId ?? "";
  const { state, account, problem, setAccount, containerRef, requestRefresh } =
    useBankAccountEditLoader({
      accountId,
      expectedBankId: bankId,
      backToListTestId: BACK_TO_ACCOUNTS_TESTID,
    });

  return (
    <div
      ref={containerRef}
      tabIndex={-1}
      className="bank-accounts-edit mx-auto w-full max-w-screen-md space-y-4 outline-none sm:space-y-6"
      data-testid="bank-accounts-edit"
      data-state={state}
    >
      <Link
        href={safeHref(bankAccountRoutes.list(bankId))}
        className="text-muted-foreground hover:text-foreground inline-flex items-center gap-1 text-xs"
        aria-label="Back to accounts"
        title="Back to accounts"
        data-testid="bank-accounts-edit__back-link"
      >
        <ChevronLeft className="size-3" aria-hidden="true" />
        Back to accounts
      </Link>

      {state === ViewStatus.LOADING ? (
        <p
          className="text-muted-foreground text-sm"
          role="status"
          aria-live="polite"
          data-testid="bank-accounts-edit__loading"
        >
          Loading account…
        </p>
      ) : null}

      {state === ViewStatus.NOT_FOUND && problem ? (
        <div data-testid="bank-accounts-edit__not-found">
          <EmptyState
            variant="first-run"
            heading="Account not found"
            description="We could not find an account with that id. It may have been deleted."
            action={
              <div className="flex flex-col items-center gap-2">
                <CorrelationIdChip id={problem["correlation-id"]} label="Error ID:" />
                <Link
                  href={safeHref(bankAccountRoutes.list(bankId))}
                  className={cn(buttonVariants())}
                  data-testid={BACK_TO_ACCOUNTS_TESTID}
                >
                  Back to accounts
                </Link>
              </div>
            }
          />
        </div>
      ) : null}

      {state === ViewStatus.ERROR && problem ? (
        <div data-testid="bank-accounts-edit__error">
          <ProblemDisplay problem={problem} variant="panel" />
        </div>
      ) : null}

      {state === ViewStatus.READY && account ? (
        <>
          <header
            className="bank-accounts-edit__header space-y-1"
            data-testid="bank-accounts-edit__header"
          >
            <h1
              className="text-foreground text-xl font-semibold tracking-tight sm:text-2xl"
              data-testid="bank-accounts-edit__title"
            >
              Edit account
            </h1>
            <p
              className="text-muted-foreground text-sm break-words"
              data-testid="bank-accounts-edit__subtitle"
            >
              Update {account.holderName}.
            </p>
          </header>

          <BankAccountForm
            key={account.id}
            mode={PersistenceAction.UPDATING}
            bankId={bankId}
            initial={{
              id: account.id,
              holderName: account.holderName,
              iban: account.iban,
              bic: account.bic,
              alias: account.alias,
              currency: account.currency,
            }}
            onStaleAccount={requestRefresh}
          />

          <BankAccountStatusControl account={account} onChanged={setAccount} />
        </>
      ) : null}
    </div>
  );
}
