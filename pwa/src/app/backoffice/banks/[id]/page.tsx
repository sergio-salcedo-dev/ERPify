"use client";

import { useCallback, useEffect, useState, type ReactNode } from "react";
import Link from "next/link";
import { useParams, useRouter } from "next/navigation";
import { ChevronLeft, Clock, Pencil, RefreshCw } from "lucide-react";
import { container } from "@/context/shared/infrastructure/DependencyInjection/Container";
import { FindBank } from "@/context/backoffice/bank/application/FindBank";
import type { Bank } from "@/context/backoffice/bank/domain/Bank";
import { HttpError } from "@/context/shared/infrastructure/HttpClient/HttpError";
import type { ProblemDetails } from "@/context/shared/domain/ProblemDetails";
import {
  CopyButton,
  CorrelationIdChip,
  EmptyState,
  ProblemDisplay,
  StatusBadge,
} from "@/components/erpify";
import { buttonVariants } from "@/components/ui/button-variants";
import { cn } from "@/lib/utils";
import { isUuid, uuidV7 } from "@/lib/uuidV7";
import { dateTimeProvider } from "@/context/shared/infrastructure/DateTimeProvider";
import { isRecentlyCreated } from "../_lib/bankRecency";
import { safeHref } from "@/lib/safeHref";
import { toastNotifier } from "@/context/shared/infrastructure/Notification/Toast";
import { ViewStatus } from "@/context/shared/domain/types/status";
import { HttpStatus } from "@/context/shared/domain/types/http";
import { bankRoutes } from "../_lib/bankRoutes";
import { DeleteBankButton } from "../_components/DeleteBankButton";
import { bankTopics, useBankRealtime } from "@/context/backoffice/bank/infrastructure/bankRealtime";

type State = ViewStatus;

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

export default function BankDetailPage() {
  const params = useParams<{ id: string }>();
  const router = useRouter();
  const id = params?.id ?? "";
  const [state, setState] = useState<State>(ViewStatus.LOADING);
  const [bank, setBank] = useState<Bank | null>(null);
  const [problem, setProblem] = useState<ProblemDetails | null>(null);
  // Set once a delete succeeds: suppress the detail UI (so the now-deleted id
  // never refetches into a "Bank not found" flash) while we redirect cleanly
  // to the list, where the success toast lands.
  const [redirecting, setRedirecting] = useState(false);

  // Reset state when ID changes to avoid synchronous setState in useEffect
  const [prevId, setPrevId] = useState(id);
  if (id !== prevId) {
    setPrevId(id);
    setState(ViewStatus.LOADING);
    setBank(null);
    setProblem(null);
  }

  function handleDeleted(): void {
    setRedirecting(true);
    toastNotifier.success("Bank deleted", bank ? { description: bank.name } : undefined);
    router.push(bankRoutes.list);
  }

  // Loads the bank by id. `silent` skips the LOADING flash and keeps the current
  // bank on a transient failure (used for the realtime reconnect reconcile);
  // `isCancelled` lets a superseded load (id changed mid-flight) drop its result.
  const loadBank = useCallback(
    async (options?: { silent?: boolean; isCancelled?: () => boolean }): Promise<void> => {
      if (!id || redirecting) return;
      try {
        const useCase = container.get<FindBank>("BackOfficeFindBank");
        const result = await useCase.run(id);
        if (options?.isCancelled?.()) return;
        setBank(result);
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

  // Real-time sync from OTHER clients (Mercure): reflect remote edits live, and
  // on a remote delete fall through to the same redirect-to-list flow as a local
  // delete so the now-gone id never refetches into a "not found" flash.
  // Validate the route id as a UUID before it flows into the Mercure topic IRI
  // (defense in depth): a malformed id never opens a junk subscription, and the
  // detail fetch below already rejects it with a 400/404.
  useBankRealtime(id && isUuid(id) ? [bankTopics.detail(id)] : [], {
    onUpdated: (incoming) => {
      if (incoming.id === id) {
        setBank(incoming);
      }
    },
    onDeleted: (deletedId) => {
      if (deletedId === id && !redirecting) {
        handleDeleted();
      }
    },
    // On stream re-open after a drop, silently re-fetch this bank to reconcile an
    // update/delete missed during the gap (Mercure has no replay). Fire-and-forget:
    // a block body keeps the callback's `void` return so the promise isn't returned.
    onReconnect: () => {
      loadBank({ silent: true });
    },
  });

  useEffect(() => {
    // loadBank only setState()s after an `await` (or not at all on the guard
    // path), so it cannot cascade renders synchronously — the rule can't see
    // through the stable useCallback boundary. Mirrors the list page's loadBanks.
    let cancelled = false;
    // Fire-and-forget: loadBank handles its own errors, so the floating promise is
    // intentional.
    // eslint-disable-next-line react-hooks/set-state-in-effect
    loadBank({ isCancelled: () => cancelled });
    return () => {
      cancelled = true;
    };
  }, [loadBank]);

  return (
    <div
      className="banks-detail mx-auto w-full max-w-screen-2xl space-y-4 sm:space-y-6 2xl:max-w-[120rem]"
      data-testid="banks-detail"
      data-state={state}
    >
      <BackLink />

      {redirecting ? (
        <p
          className="text-muted-foreground text-sm"
          role="status"
          aria-live="polite"
          data-testid="banks-detail__redirecting"
        >
          Returning to the banks list…
        </p>
      ) : null}

      {!redirecting && state === ViewStatus.LOADING ? (
        <p
          className="text-muted-foreground text-sm"
          role="status"
          aria-live="polite"
          data-testid="banks-detail__loading"
        >
          Loading bank…
        </p>
      ) : null}

      {!redirecting && state === ViewStatus.NOT_FOUND && problem ? (
        <div data-testid="banks-detail__not-found">
          <EmptyState
            variant="first-run"
            heading="Bank not found"
            description="We could not find a bank with that id. It may have been deleted."
            action={
              <div className="flex flex-col items-center gap-2">
                <CorrelationIdChip id={problem["correlation-id"]} label="Error ID:" />
                <Link
                  href={bankRoutes.list}
                  className={cn(buttonVariants())}
                  data-testid="banks-detail__back-to-list"
                >
                  Back to banks
                </Link>
              </div>
            }
          />
        </div>
      ) : null}

      {!redirecting && state === ViewStatus.ERROR && problem ? (
        <div data-testid="banks-detail__error">
          <ProblemDisplay problem={problem} variant="panel" />
        </div>
      ) : null}

      {!redirecting && state === ViewStatus.READY && bank ? (
        <>
          <header
            className="banks-detail__header flex flex-col gap-3 sm:flex-row sm:items-start sm:justify-between"
            data-testid="banks-detail__header"
          >
            <div className="flex min-w-0 items-start gap-3">
              <div className="min-w-0">
                <div className="flex min-w-0 items-center gap-2">
                  <h1
                    className="text-foreground line-clamp-2 min-w-0 text-xl font-semibold tracking-tight break-words sm:text-2xl"
                    data-testid="banks-detail__name"
                  >
                    {bank.name}
                  </h1>
                  {isRecentlyCreated(bank.createdAt, dateTimeProvider) ? (
                    <StatusBadge
                      variant="success"
                      label="New"
                      className="banks-detail__new flex-none"
                      testId="banks-detail__new-badge"
                    />
                  ) : null}
                </div>
                <p
                  className="text-muted-foreground mt-1 font-mono text-sm uppercase break-words"
                  data-testid="banks-detail__shortname"
                >
                  {bank.shortName}
                </p>
              </div>
            </div>
            <div className="flex flex-wrap items-center gap-2 sm:flex-nowrap">
              <Link
                href={safeHref(bankRoutes.edit(bank.id))}
                className={cn(buttonVariants({ variant: "outline", size: "sm" }))}
                data-icon="inline-start"
                data-testid="banks-detail__edit-button"
                aria-label={`Edit bank ${bank.name}`}
                title={`Edit bank ${bank.name}`}
              >
                <Pencil className="size-3.5" aria-hidden="true" />
                Edit
              </Link>
              <DeleteBankButton id={bank.id} name={bank.name} onDeleted={handleDeleted} />
            </div>
          </header>

          <dl
            className="banks-detail__meta border-border bg-card grid grid-cols-1 gap-4 rounded-lg border p-4 sm:grid-cols-2"
            data-testid="banks-detail__meta"
          >
            <Field label="Name" value={bank.name} testId="banks-detail__field-name" />
            <Field
              label="Short name"
              value={bank.shortName}
              valueClassName="font-mono text-xs uppercase"
              testId="banks-detail__field-shortname"
            />
            <Field
              label="Created"
              value={dateTimeProvider.formatIsoToRelative(bank.createdAt)}
              valueTitle={dateTimeProvider.formatIsoToLocalDateTime(bank.createdAt)}
              icon={<Clock className="size-3.5" aria-hidden="true" />}
              testId="banks-detail__field-created"
            />
            <Field
              label="Updated"
              value={dateTimeProvider.formatIsoToRelative(bank.updatedAt)}
              valueTitle={dateTimeProvider.formatIsoToLocalDateTime(bank.updatedAt)}
              icon={<RefreshCw className="size-3.5" aria-hidden="true" />}
              testId="banks-detail__field-updated"
            />
            <div className="banks-detail__field sm:col-span-2">
              <dt className="text-muted-foreground text-xs font-medium tracking-wide uppercase">
                Identifier
              </dt>
              <dd className="mt-1 flex items-center gap-2">
                <span
                  className="banks-detail__id text-foreground min-w-0 truncate font-mono text-xs"
                  data-testid="banks-detail__id"
                >
                  {bank.id}
                </span>
                <CopyButton
                  value={bank.id}
                  iconOnly
                  size="icon-sm"
                  label="Copy ID"
                  copiedLabel="ID copied"
                  errorLabel="Copy failed"
                  title={`Copy bank ID ${bank.id}`}
                  testId="banks-detail__id-copy"
                />
              </dd>
            </div>
          </dl>
        </>
      ) : null}
    </div>
  );
}

function BackLink() {
  return (
    <Link
      href={bankRoutes.list}
      className="text-muted-foreground hover:text-foreground inline-flex items-center gap-1 text-xs"
      aria-label="Back to banks"
      title="Back to banks"
      data-testid="banks-detail__back-link"
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
    <div className="banks-detail__field">
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
