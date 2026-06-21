"use client";

import { useEffect, useRef, useState } from "react";
import Link from "next/link";
import { useParams } from "next/navigation";
import { ChevronLeft } from "lucide-react";
import { container } from "@/context/shared/dependency-injection/infrastructure/Container";
import { FindBank } from "@/context/backoffice/bank/application/FindBank";
import type { Bank } from "@/context/backoffice/bank/domain/Bank";
import { HttpError } from "@/context/shared/http-client/domain/HttpError";
import type { ProblemDetails } from "@/context/shared/error/domain/ProblemDetails";
import { CorrelationIdChip, EmptyState, ProblemDisplay } from "@/components/erpify";
import { buttonVariants } from "@/components/ui/button-variants";
import { cn } from "@/components/cn";
import { uuidV7 } from "@/context/shared/uuid/infrastructure/uuidV7";
import { safeHref } from "@/context/shared/navigation/domain/safeHref";
import { PersistenceAction, ViewStatus } from "@/context/shared/view-state/domain/ViewState";
import { HttpStatus } from "@/context/shared/http-client/domain/HttpStatus";
import { bankRoutes } from "../../_lib/bankRoutes";
import { BankForm } from "../../_components/BankForm";

type State = ViewStatus;

/** Single source — the form's stale-404 Refresh focuses this CTA once not-found lands. */
const BACK_TO_LIST_TESTID = "banks-edit__back-to-list";

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

export default function EditBankPage() {
  const params = useParams<{ id: string }>();
  const id = params?.id ?? "";
  const [state, setState] = useState<State>(ViewStatus.LOADING);
  const [bank, setBank] = useState<Bank | null>(null);
  const [problem, setProblem] = useState<ProblemDetails | null>(null);
  // Bumped by the form's "Refresh" recovery action (stale 404). Re-runs the
  // load so a bank deleted in parallel lands on the not-found empty state.
  const [reloadToken, setReloadToken] = useState(0);
  // Armed when the refresh is form-initiated: once the reload settles, focus
  // the "Back to banks" CTA — or the page container when the landing has no
  // CTA (error/ready) — so unmounting the form never strands focus on <body>
  // (mirrors the detail page's pendingRefreshFocusRef pattern).
  const pendingRefreshFocusRef = useRef(false);
  const containerRef = useRef<HTMLDivElement | null>(null);

  // Reset state when ID changes to avoid synchronous setState in useEffect
  const [prevId, setPrevId] = useState(id);
  if (id !== prevId) {
    setPrevId(id);
    setState(ViewStatus.LOADING);
    setBank(null);
    setProblem(null);
  }

  useEffect(() => {
    if (!id) return;
    let cancelled = false;
    (async () => {
      try {
        const useCase = container.get<FindBank>("BackOfficeFindBank");
        const result = await useCase.run(id);
        if (cancelled) return;
        setBank(result);
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
  }, [id, reloadToken]);

  useEffect(() => {
    if (!pendingRefreshFocusRef.current || state === ViewStatus.LOADING) return;
    pendingRefreshFocusRef.current = false;
    const backToList = globalThis.document.querySelector<HTMLElement>(
      `[data-testid='${BACK_TO_LIST_TESTID}']`,
    );
    (backToList ?? containerRef.current)?.focus();
  }, [state, bank]);

  return (
    <div
      ref={containerRef}
      tabIndex={-1}
      className="banks-edit mx-auto w-full max-w-screen-md space-y-4 outline-none sm:space-y-6"
      data-testid="banks-edit"
      data-state={state}
    >
      <BackLink id={id} />

      {state === ViewStatus.LOADING ? (
        <p
          className="text-muted-foreground text-sm"
          role="status"
          aria-live="polite"
          data-testid="banks-edit__loading"
        >
          Loading bank…
        </p>
      ) : null}

      {state === ViewStatus.NOT_FOUND && problem ? (
        <div data-testid="banks-edit__not-found">
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
                  data-testid={BACK_TO_LIST_TESTID}
                >
                  Back to banks
                </Link>
              </div>
            }
          />
        </div>
      ) : null}

      {state === ViewStatus.ERROR && problem ? (
        <div data-testid="banks-edit__error">
          <ProblemDisplay problem={problem} variant="panel" />
        </div>
      ) : null}

      {state === ViewStatus.READY && bank ? (
        <>
          <header className="banks-edit__header space-y-1" data-testid="banks-edit__header">
            <h1
              className="text-foreground text-xl font-semibold tracking-tight sm:text-2xl"
              data-testid="banks-edit__title"
            >
              Edit bank
            </h1>
            <p
              className="text-muted-foreground text-sm break-words"
              data-testid="banks-edit__subtitle"
            >
              Update {bank.name}.
            </p>
          </header>

          <BankForm
            key={bank.id}
            mode={PersistenceAction.UPDATING}
            initial={{ id: bank.id, name: bank.name, shortName: bank.shortName }}
            onStaleBank={() => {
              pendingRefreshFocusRef.current = true;
              setReloadToken((token) => token + 1);
            }}
          />
        </>
      ) : null}
    </div>
  );
}

function BackLink({ id }: Readonly<{ id: string }>) {
  return (
    <Link
      href={safeHref(id ? bankRoutes.detail(id) : bankRoutes.list)}
      className="text-muted-foreground hover:text-foreground inline-flex items-center gap-1 text-xs"
      aria-label="Back to bank detail"
      title="Back to bank detail"
      data-testid="banks-edit__back-link"
    >
      <ChevronLeft className="size-3" aria-hidden="true" />
      Back to bank
    </Link>
  );
}
