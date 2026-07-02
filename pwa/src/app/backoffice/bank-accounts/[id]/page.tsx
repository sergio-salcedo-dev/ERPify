"use client";

import { useCallback, useEffect, useRef, useState, type ReactNode } from "react";
import Link from "next/link";
import { useParams, useRouter } from "next/navigation";
import { ChevronLeft, Clock, Landmark, Pencil, RefreshCw } from "lucide-react";
import { container } from "@/context/shared/dependency-injection/infrastructure/Container";
import { FindBankAccount } from "@/context/backoffice/bankaccount/application/FindBankAccount";
import type { BankAccount } from "@/context/backoffice/bankaccount/domain/BankAccount";
import {
  bankAccountTopics,
  useBankAccountRealtime,
} from "@/context/backoffice/bankaccount/infrastructure/bankAccountRealtime";
import { HttpError } from "@/context/shared/http-client/domain/HttpError";
import { HttpStatus } from "@/context/shared/http-client/domain/HttpStatus";
import type { ProblemDetails } from "@/context/shared/error/domain/ProblemDetails";
import {
  CopyButton,
  CorrelationIdChip,
  EmptyState,
  ProblemDisplay,
  StatusBadge,
} from "@/components/erpify";
import { buttonVariants } from "@/components/ui/button-variants";
import { cn } from "@/components/cn";
import { uuidV7 } from "@/context/shared/uuid/infrastructure/uuidV7";
import { isUuid } from "@/context/shared/uuid/infrastructure/isUuid";
import { dateTimeProvider } from "@/context/shared/date-time-provider/infrastructure";
import { safeHref } from "@/context/shared/navigation/domain/safeHref";
import { toastNotifier } from "@/context/shared/notification/infrastructure/Toast";
import { ViewStatus } from "@/context/shared/view-state/domain/ViewState";
import { bankRoutes } from "../../banks/_lib/bankRoutes";
import { bankAccountRoutes as nestedBankAccountRoutes } from "../../banks/[id]/accounts/_lib/bankAccountRoutes";
import { bankAccountRoutes } from "../_lib/bankAccountRoutes";
import { BANK_ACCOUNT_STATUS_LABEL, BANK_ACCOUNT_STATUS_VARIANT } from "../_lib/bankAccountStatus";
import { IbanCell } from "../../banks/[id]/accounts/_components/IbanCell";

type State = ViewStatus;

/** Single source — the stale-delete Refresh focuses this CTA once the not-found state lands. */
const BACK_TO_LIST_TESTID = "bank-accounts-detail__back-to-list";

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

// Validate the route id as a UUID before it flows into the Mercure detail topic
// IRI (defense in depth): a malformed id never opens a junk subscription, and
// the detail fetch already rejects it with a 400/404. Subscribing to
// `bankAccountTopics.detail(id)` is what WIRES the per-account topic end-to-end.
function detailTopics(id: string): string[] {
  return id && isUuid(id) ? [bankAccountTopics.detail(id)] : [];
}

export default function BankAccountDetailPage() {
  const params = useParams<{ id: string }>();
  const router = useRouter();
  const id = params?.id ?? "";
  const [state, setState] = useState<State>(ViewStatus.LOADING);
  const [account, setAccount] = useState<BankAccount | null>(null);
  const [problem, setProblem] = useState<ProblemDetails | null>(null);
  const [revealed, setRevealed] = useState(false);
  const containerRef = useRef<HTMLDivElement>(null);
  // Set once a remote delete lands: suppress the detail UI (so the now-deleted id
  // never refetches into a "not found" flash) while we redirect to the list.
  const [redirecting, setRedirecting] = useState(false);

  // Always holds the id currently shown. An in-flight silent refetch started for a
  // previous id (a realtime `updated`/reconnect resolving after the route id
  // changed via back/forward) checks this and drops its result instead of
  // clobbering the newer account.
  const currentIdRef = useRef(id);

  // Reset state when the id changes to avoid a synchronous setState in an effect.
  const [prevId, setPrevId] = useState(id);
  if (id !== prevId) {
    setPrevId(id);
    setState(ViewStatus.LOADING);
    setAccount(null);
    setProblem(null);
    setRevealed(false);
  }

  // Keep the "current id" ref in sync so a silent refetch that resolves after the
  // route id changed can detect the change and drop its now-stale result.
  useEffect(() => {
    currentIdRef.current = id;
  }, [id]);

  const handleDeleted = useCallback((): void => {
    setRedirecting(true);
    toastNotifier.info("Account deleted", {
      description: "It was removed and is no longer available.",
    });
    router.push(bankAccountRoutes.list);
  }, [router]);

  // Loads the account by id. `silent` skips the LOADING flash and keeps the
  // current account on a transient failure (realtime reconcile / update refetch);
  // `isCancelled` lets a superseded load (id changed mid-flight) drop its result.
  const loadAccount = useCallback(
    async (options?: { silent?: boolean; isCancelled?: () => boolean }): Promise<void> => {
      if (!id || redirecting) return;
      try {
        const useCase = container.get<FindBankAccount>("BackOfficeFindBankAccount");
        const result = await useCase.run(id);
        if (options?.isCancelled?.() || currentIdRef.current !== id) return;
        setAccount(result);
        setState(ViewStatus.READY);
      } catch (err) {
        if (options?.isCancelled?.() || options?.silent) return;
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
    },
    [id, redirecting],
  );

  // Real-time sync from OTHER clients over the per-account detail topic:
  // the broadcast is PII-free, so an `updated` event REFETCHES rather than reading
  // account data off the payload; a `deleted` event redirects to the list so the
  // gone id never refetches into a "not found" flash; a reconnect reconciles by a
  // silent refetch (Mercure has no replay).
  useBankAccountRealtime(detailTopics(id), {
    onUpdated: (event) => {
      if (event.id === id) {
        loadAccount({ silent: true });
      }
    },
    onDeleted: (event) => {
      if (event.id === id && !redirecting) {
        handleDeleted();
      }
    },
    onReconnect: () => {
      loadAccount({ silent: true });
    },
  });

  useEffect(() => {
    let cancelled = false;
    // Fire-and-forget: loadAccount handles its own errors, so the floating
    // promise is intentional.
    // eslint-disable-next-line react-hooks/set-state-in-effect
    loadAccount({ isCancelled: () => cancelled });
    return () => {
      cancelled = true;
    };
  }, [loadAccount]);

  return (
    <div
      ref={containerRef}
      tabIndex={-1}
      className="bank-accounts-detail mx-auto w-full max-w-screen-2xl space-y-4 outline-none sm:space-y-6 2xl:max-w-[120rem]"
      data-testid="bank-accounts-detail"
      data-state={state}
    >
      <BackLink />

      {redirecting ? (
        <p
          className="text-muted-foreground text-sm"
          role="status"
          aria-live="polite"
          data-testid="bank-accounts-detail__redirecting"
        >
          Returning to the accounts list…
        </p>
      ) : null}

      {!redirecting && state === ViewStatus.LOADING ? (
        <p
          className="text-muted-foreground text-sm"
          role="status"
          aria-live="polite"
          data-testid="bank-accounts-detail__loading"
        >
          Loading account…
        </p>
      ) : null}

      {!redirecting && state === ViewStatus.NOT_FOUND && problem ? (
        <div data-testid="bank-accounts-detail__not-found">
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

      {!redirecting && state === ViewStatus.ERROR && problem ? (
        <div data-testid="bank-accounts-detail__error">
          <ProblemDisplay problem={problem} variant="panel" />
        </div>
      ) : null}

      {!redirecting && state === ViewStatus.READY && account ? (
        <BankAccountDetailReady
          account={account}
          revealed={revealed}
          onReveal={() => setRevealed(true)}
          onHide={() => setRevealed(false)}
        />
      ) : null}
    </div>
  );
}

function BankAccountDetailReady({
  account,
  revealed,
  onReveal,
  onHide,
}: Readonly<{
  account: BankAccount;
  revealed: boolean;
  onReveal: () => void;
  onHide: () => void;
}>) {
  // The detail/write resource always carries `bankId`; guard anyway so a partial
  // payload never builds a broken edit href.
  const bankId = account.bankId;

  return (
    <>
      <header
        className="bank-accounts-detail__header flex flex-col gap-3 sm:flex-row sm:items-start sm:justify-between"
        data-testid="bank-accounts-detail__header"
      >
        <div className="min-w-0">
          <h1
            className="text-foreground min-w-0 text-xl font-semibold tracking-tight break-words sm:text-2xl"
            data-testid="bank-accounts-detail__holder"
          >
            {account.holderName}
          </h1>
          <div className="mt-1 flex min-w-0 items-center gap-2">
            <StatusBadge
              variant={BANK_ACCOUNT_STATUS_VARIANT[account.status]}
              label={BANK_ACCOUNT_STATUS_LABEL[account.status]}
              testId="bank-accounts-detail__status"
            />
          </div>
        </div>
        {bankId ? (
          <div className="flex flex-wrap items-center gap-2 sm:flex-nowrap">
            <Link
              href={safeHref(nestedBankAccountRoutes.edit(bankId, account.id))}
              className={cn(buttonVariants({ variant: "outline", size: "sm" }))}
              data-icon="inline-start"
              data-testid="bank-accounts-detail__edit-button"
              aria-label={`Edit account ${account.holderName}`}
              title={`Edit account ${account.holderName}`}
            >
              <Pencil className="size-3.5" aria-hidden="true" />
              Edit
            </Link>
          </div>
        ) : null}
      </header>

      <dl
        className="bank-accounts-detail__meta border-border bg-card grid grid-cols-1 gap-4 rounded-lg border p-4 sm:grid-cols-2"
        data-testid="bank-accounts-detail__meta"
      >
        <Field
          label="Holder"
          value={account.holderName}
          testId="bank-accounts-detail__field-holder"
        />
        <div className="bank-accounts-detail__field">
          <dt className="text-muted-foreground text-xs font-medium tracking-wide uppercase">
            IBAN
          </dt>
          <dd className="mt-1">
            <IbanCell
              iban={account.iban}
              revealed={revealed}
              onReveal={onReveal}
              onHide={onHide}
              testId="bank-accounts-detail__iban"
            />
          </dd>
        </div>
        <Field
          label="BIC"
          value={account.bic ?? "—"}
          valueClassName="font-mono text-xs uppercase"
          testId="bank-accounts-detail__field-bic"
        />
        <Field
          label="Alias"
          value={account.alias ?? "—"}
          testId="bank-accounts-detail__field-alias"
        />
        <Field
          label="Currency"
          value={account.currency}
          valueClassName="font-mono"
          testId="bank-accounts-detail__field-currency"
        />
        {bankId ? (
          <div className="bank-accounts-detail__field">
            <dt className="text-muted-foreground flex items-center gap-1.5 text-xs font-medium tracking-wide uppercase">
              <Landmark className="size-3.5" aria-hidden="true" />
              Bank
            </dt>
            <dd className="mt-1 text-sm">
              <Link
                href={safeHref(bankRoutes.detail(bankId))}
                className="text-[var(--erpify-brand)] hover:underline"
                data-testid="bank-accounts-detail__bank-link"
              >
                View bank
              </Link>
            </dd>
          </div>
        ) : null}
        {account.createdAt ? (
          <Field
            label="Created"
            value={dateTimeProvider.formatIsoToRelative(account.createdAt)}
            valueTitle={dateTimeProvider.formatIsoToLocalDateTime(account.createdAt)}
            icon={<Clock className="size-3.5" aria-hidden="true" />}
            testId="bank-accounts-detail__field-created"
          />
        ) : null}
        {account.updatedAt ? (
          <Field
            label="Updated"
            value={dateTimeProvider.formatIsoToRelative(account.updatedAt)}
            valueTitle={dateTimeProvider.formatIsoToLocalDateTime(account.updatedAt)}
            icon={<RefreshCw className="size-3.5" aria-hidden="true" />}
            testId="bank-accounts-detail__field-updated"
          />
        ) : null}
        <div className="bank-accounts-detail__field sm:col-span-2">
          <dt className="text-muted-foreground text-xs font-medium tracking-wide uppercase">
            Identifier
          </dt>
          <dd className="mt-1 flex items-center gap-2">
            <span
              className="bank-accounts-detail__id text-foreground min-w-0 truncate font-mono text-xs"
              data-testid="bank-accounts-detail__id"
            >
              {account.id}
            </span>
            <CopyButton
              value={account.id}
              iconOnly
              size="icon-sm"
              label="Copy ID"
              copiedLabel="ID copied"
              errorLabel="Copy failed"
              title={`Copy account ID ${account.id}`}
              testId="bank-accounts-detail__id-copy"
            />
          </dd>
        </div>
      </dl>
    </>
  );
}

function BackLink() {
  return (
    <Link
      href={bankAccountRoutes.list}
      className="text-muted-foreground hover:text-foreground inline-flex items-center gap-1 text-xs"
      aria-label="Back to bank accounts"
      title="Back to bank accounts"
      data-testid="bank-accounts-detail__back-link"
    >
      <ChevronLeft className="size-3" aria-hidden="true" />
      Back to bank accounts
    </Link>
  );
}

function Field({
  label,
  value,
  valueClassName,
  valueTitle,
  icon,
  testId,
}: Readonly<{
  label: string;
  value: string;
  valueClassName?: string;
  valueTitle?: string;
  icon?: ReactNode;
  testId?: string;
}>) {
  return (
    <div className="bank-accounts-detail__field">
      <dt className="text-muted-foreground flex items-center gap-1.5 text-xs font-medium tracking-wide uppercase">
        {icon}
        {label}
      </dt>
      <dd
        className={cn("text-foreground mt-1 text-sm", valueClassName)}
        title={valueTitle}
        data-testid={testId}
      >
        {value}
      </dd>
    </div>
  );
}
