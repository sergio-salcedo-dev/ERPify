"use client";

import { useCallback, useEffect, useMemo, useRef, useState, type ChangeEvent } from "react";
import { Download, FilePlus2, RefreshCw } from "lucide-react";
import { container } from "@/context/shared/dependency-injection/infrastructure/Container";
import {
  AttachBankAccountEvidence,
  EvidenceAttachFailure,
  EvidenceAttachStage,
} from "@/context/backoffice/bankaccount/application/AttachBankAccountEvidence";
import type { ListBankAccountEvidence } from "@/context/backoffice/bankaccount/application/ListBankAccountEvidence";
import {
  EvidenceKind,
  type BankAccountEvidence,
  type EvidenceDocumentState,
} from "@/context/backoffice/bankaccount/domain/BankAccountEvidence";
import { evidenceAttachProblemMessage } from "@/context/backoffice/bankaccount/domain/evidenceProblemMessage";
import { checkDocumentFile } from "@/context/shared/documents/domain/checkDocumentFile";
import { MAX_DOCUMENT_MEBIBYTES } from "@/context/shared/documents/domain/documentLimits";
import type { DocumentUploadProgress } from "@/context/shared/documents/domain/DocumentUploader";
import { documentReadUrl } from "@/context/shared/documents/infrastructure/documentReadUrl";
import type { ProblemDetails } from "@/context/shared/error/domain/ProblemDetails";
import { NETWORK_ERROR, REQUEST_TIMEOUT } from "@/context/shared/http-client/domain/HttpClient";
import { HttpError } from "@/context/shared/http-client/domain/HttpError";
import { HttpStatus } from "@/context/shared/http-client/domain/HttpStatus";
import { dateTimeProvider } from "@/context/shared/date-time-provider/infrastructure";
import { toastNotifier } from "@/context/shared/notification/infrastructure/Toast";
import { uuidV7 } from "@/context/shared/uuid/infrastructure/uuidV7";
import {
  MutationError,
  ProblemDisplay,
  Spinner,
  StatusBadge,
  type StatusBadgeVariant,
} from "@/components/erpify";
import { Button } from "@/components/ui/button";
import { buttonVariants } from "@/components/ui/button-variants";
import { cn } from "@/components/cn";

/** The section's anchor: a fragment link to it (the delete refusal's recovery) moves the focus here. */
export const EVIDENCE_SECTION_ID = "bank-account-evidence";

const STATE_LABEL: Record<EvidenceDocumentState, string> = {
  received: "Received",
  scanning: "Scanning",
  ready: "Ready",
  quarantined: "Quarantined",
  scan_failed: "Scan failed",
  erased: "Erased",
};

const STATE_VARIANT: Record<EvidenceDocumentState, StatusBadgeVariant> = {
  received: "info",
  scanning: "info",
  ready: "success",
  quarantined: "danger",
  scan_failed: "warning",
  erased: "neutral",
};

const KIND_LABEL: Record<EvidenceKind, string> = {
  [EvidenceKind.OWNERSHIP_CERTIFICATE]: "Ownership certificate",
};

/** The one kind this screen offers today; the retention it fixes is the domain's, not the user's. */
const OFFERED_KIND = EvidenceKind.OWNERSHIP_CERTIFICATE;

const BYTE_UNITS = ["B", "KB", "MB", "GB"] as const;

/** A size for reading, in binary multiples (the cap is stated the same way). */
export function formatByteSize(bytes: number): string {
  let value = bytes;
  let unit = 0;
  while (value >= 1024 && unit < BYTE_UNITS.length - 1) {
    value /= 1024;
    unit += 1;
  }
  const digits = unit === 0 || value >= 10 ? 0 : 1;
  return `${value.toFixed(digits)} ${BYTE_UNITS[unit]}`;
}

type ListState =
  | { readonly status: "loading" }
  | { readonly status: "ready"; readonly items: readonly BankAccountEvidence[] }
  | { readonly status: "forbidden" }
  | { readonly status: "error"; readonly problem: ProblemDetails };

type Busy =
  | { readonly stage: "upload"; readonly progress: DocumentUploadProgress | null }
  | { readonly stage: "attach" };

interface Failure {
  readonly problem: ProblemDetails;
  readonly stage: EvidenceAttachStage;
  readonly file: File;
}

function asProblem(error: unknown): ProblemDetails {
  if (error instanceof HttpError) return error.problem;
  return {
    type: "about:blank",
    title: "Unexpected error",
    status: 0,
    instance: uuidV7(),
    "correlation-id": uuidV7(),
  };
}

function failureDetail({ problem, stage, file }: Failure): string {
  const unknown = problem.type === NETWORK_ERROR || problem.type === REQUEST_TIMEOUT;
  if (stage === EvidenceAttachStage.UPLOAD) {
    return unknown
      ? `It is not known whether "${file.name}" was kept. Refresh the list before trying again.`
      : `"${file.name}" was not kept. Nothing was attached to the account.`;
  }
  return `"${file.name}" was kept as a document but is not attached to the account.`;
}

function describeFailure(failure: Failure): ProblemDetails {
  return {
    ...failure.problem,
    title: evidenceAttachProblemMessage(failure.problem, failure.stage),
    detail: failureDetail(failure),
  };
}

/**
 * The evidence of one bank account: the documents attached to it, and — for a caller allowed to
 * file — a control that keeps an original file unchanged and attaches it. Rendered only for a
 * caller holding `bankAccount.readEvidence`; a 403 still lands on a quiet "no access" line, since
 * the session's permissions are a snapshot and the server is the authority.
 */
export function BankAccountEvidenceSection({
  accountId,
  canAttach,
  canDownload,
}: Readonly<{ accountId: string; canAttach: boolean; canDownload: boolean }>) {
  const [list, setList] = useState<ListState>({ status: "loading" });
  const [busy, setBusy] = useState<Busy | null>(null);
  const [refusal, setRefusal] = useState<string | null>(null);
  const [failure, setFailure] = useState<Failure | null>(null);
  const inputRef = useRef<HTMLInputElement>(null);
  const inFlight = useRef(false);

  // Stable per failure, so MutationError's refocus-on-change fires once per failure.
  const failureProblem = useMemo(
    () => (failure === null ? null : describeFailure(failure)),
    [failure],
  );

  const load = useCallback(
    async (isCancelled: () => boolean = () => false): Promise<void> => {
      try {
        const items = await container
          .get<ListBankAccountEvidence>("BackOfficeListBankAccountEvidence")
          .run(accountId);
        if (!isCancelled()) setList({ status: "ready", items });
      } catch (error) {
        if (isCancelled()) return;
        const problem = asProblem(error);
        setList(
          problem.status === HttpStatus.FORBIDDEN
            ? { status: "forbidden" }
            : { status: "error", problem },
        );
      }
    },
    [accountId],
  );

  useEffect(() => {
    let cancelled = false;
    // Fire-and-forget: load resolves every failure into state, and sets it only after its request
    // settles, never synchronously in this effect.
    // eslint-disable-next-line react-hooks/set-state-in-effect
    void load(() => cancelled);
    return () => {
      cancelled = true;
    };
  }, [load]);

  async function attach(file: File): Promise<void> {
    if (inFlight.current) return;
    inFlight.current = true;
    setRefusal(null);
    setFailure(null);
    setBusy({ stage: "upload", progress: null });
    try {
      await container
        .get<AttachBankAccountEvidence>("BackOfficeAttachBankAccountEvidence")
        .run(accountId, file, OFFERED_KIND, (progress) => {
          setBusy(
            progress.loaded >= progress.total ? { stage: "attach" } : { stage: "upload", progress },
          );
        });
      toastNotifier.success("Evidence attached", { description: file.name });
      await load();
    } catch (error) {
      if (error instanceof EvidenceAttachFailure) {
        setFailure({ problem: asProblem(error.cause), stage: error.stage, file });
        // An attach that failed left a filed document; the list may also have moved under us.
        if (error.stage === EvidenceAttachStage.ATTACH) void load();
      } else {
        setFailure({ problem: asProblem(error), stage: EvidenceAttachStage.UPLOAD, file });
      }
    } finally {
      inFlight.current = false;
      setBusy(null);
    }
  }

  function handlePick(event: ChangeEvent<HTMLInputElement>): void {
    const file = event.target.files?.[0];
    // Cleared so the same file can be picked again after a failure.
    event.target.value = "";
    if (!file || inFlight.current) return;
    const check = checkDocumentFile(file);
    if (!check.ok) {
      setFailure(null);
      setRefusal(check.message);
      return;
    }
    void attach(file);
  }

  const percent =
    busy?.stage === "upload" && busy.progress !== null && busy.progress.total > 0
      ? Math.floor((busy.progress.loaded / busy.progress.total) * 100)
      : null;

  return (
    <section
      id={EVIDENCE_SECTION_ID}
      tabIndex={-1}
      className="bank-accounts-evidence outline-none border-border bg-card space-y-4 rounded-lg border p-4"
      aria-labelledby="bank-accounts-detail-evidence-heading"
      aria-busy={busy !== null || list.status === "loading"}
      data-testid="bank-accounts-detail__evidence"
    >
      <div className="bank-accounts-evidence__header space-y-1">
        <h2
          id="bank-accounts-detail-evidence-heading"
          className="text-foreground text-base font-semibold"
        >
          Evidence
        </h2>
        <p className="text-muted-foreground text-sm">
          Documents kept as proof about this account, stored exactly as they were uploaded.
        </p>
      </div>

      {list.status === "loading" ? (
        <p
          className="text-muted-foreground text-sm"
          data-testid="bank-accounts-detail__evidence-loading"
        >
          Loading evidence…
        </p>
      ) : null}

      {list.status === "forbidden" ? (
        <p
          className="text-muted-foreground text-sm"
          data-testid="bank-accounts-detail__evidence-forbidden"
        >
          You don&apos;t have access to this account&apos;s evidence.
        </p>
      ) : null}

      {list.status === "error" ? (
        <div data-testid="bank-accounts-detail__evidence-error">
          <ProblemDisplay
            problem={list.problem}
            variant="inline"
            action={
              <Button
                type="button"
                variant="outline"
                size="sm"
                onClick={() => {
                  setList({ status: "loading" });
                  void load();
                }}
                aria-label="Retry"
                title="Load the evidence again"
                data-testid="bank-accounts-detail__evidence-retry"
              >
                <RefreshCw className="size-3.5" aria-hidden="true" />
                Retry
              </Button>
            }
          />
        </div>
      ) : null}

      {list.status === "ready" && list.items.length === 0 ? (
        <p
          className="text-muted-foreground text-sm"
          data-testid="bank-accounts-detail__evidence-empty"
        >
          No evidence attached yet.
        </p>
      ) : null}

      {list.status === "ready" && list.items.length > 0 ? (
        <ul
          className="bank-accounts-evidence__list divide-border divide-y"
          data-testid="bank-accounts-detail__evidence-list"
        >
          {list.items.map((item) => (
            <EvidenceRow key={item.id} item={item} canDownload={canDownload} />
          ))}
        </ul>
      ) : null}

      {canAttach ? (
        <div
          className="bank-accounts-evidence__attach border-border space-y-2 border-t pt-4"
          data-testid="bank-accounts-detail__evidence-attach"
        >
          <p className="text-foreground text-sm font-medium">
            Attach evidence: {KIND_LABEL[OFFERED_KIND]}
          </p>
          <p
            id="bank-accounts-detail-evidence-help"
            className="text-muted-foreground text-xs"
            data-testid="bank-accounts-detail__evidence-help"
          >
            {`The original file is kept unchanged, byte for byte, as evidence — it is never converted or re-encoded. Any file type, up to ${MAX_DOCUMENT_MEBIBYTES} MB.`}
          </p>
          <input
            ref={inputRef}
            type="file"
            hidden
            disabled={busy !== null}
            onChange={handlePick}
            data-testid="bank-accounts-detail__evidence-input"
          />
          <div className="flex flex-wrap items-center gap-3">
            <Button
              type="button"
              variant="outline"
              size="sm"
              disabled={busy !== null}
              aria-label="Attach evidence"
              aria-describedby="bank-accounts-detail-evidence-help"
              title={`Attach an original file as ${KIND_LABEL[OFFERED_KIND].toLowerCase()} evidence`}
              onClick={() => inputRef.current?.click()}
              data-testid="bank-accounts-detail__evidence-attach-button"
            >
              {busy === null ? <FilePlus2 className="size-4" aria-hidden="true" /> : <Spinner />}
              Attach original file
            </Button>
            {busy?.stage === "upload" ? (
              <div className="flex items-center gap-2">
                <progress
                  className="bank-accounts-evidence__progress h-2 w-40"
                  max={100}
                  value={percent ?? undefined}
                  aria-label="Upload progress"
                  data-testid="bank-accounts-detail__evidence-progress"
                />
                <span className="text-muted-foreground text-sm tabular-nums">
                  {percent === null ? "Uploading…" : `${percent}%`}
                </span>
              </div>
            ) : null}
            {busy?.stage === "attach" ? (
              <span
                className="text-muted-foreground text-sm"
                data-testid="bank-accounts-detail__evidence-attaching"
              >
                Attaching…
              </span>
            ) : null}
          </div>
          {refusal === null ? null : (
            <p
              role="alert"
              className="text-destructive text-sm"
              data-testid="bank-accounts-detail__evidence-refusal"
            >
              {refusal}
            </p>
          )}
          {failure === null || failureProblem === null ? null : (
            <MutationError
              problem={failureProblem}
              onDismiss={() => setFailure(null)}
              action={
                failure.stage === EvidenceAttachStage.UPLOAD ? (
                  <Button
                    type="button"
                    variant="outline"
                    size="sm"
                    disabled={busy !== null}
                    aria-label="Try again"
                    title={`Try uploading ${failure.file.name} again`}
                    onClick={() => void attach(failure.file)}
                    data-testid="bank-accounts-detail__evidence-retry-upload"
                  >
                    Try again
                  </Button>
                ) : undefined
              }
              testId="bank-accounts-detail__evidence-attach-error"
            />
          )}
        </div>
      ) : null}
    </section>
  );
}

function EvidenceRow({
  item,
  canDownload,
}: Readonly<{ item: BankAccountEvidence; canDownload: boolean }>) {
  const name = item.documentName ?? "Unnamed document";
  const href =
    canDownload && item.documentState === "ready" ? documentReadUrl(item.documentId) : null;
  return (
    <li
      className="bank-accounts-evidence__item flex flex-wrap items-center gap-x-4 gap-y-1 py-2"
      data-testid={`bank-accounts-detail__evidence-item-${item.id}`}
    >
      <span
        className="text-foreground min-w-0 flex-1 truncate text-sm font-medium"
        data-testid={`bank-accounts-detail__evidence-name-${item.id}`}
      >
        {name}
      </span>
      <span className="text-muted-foreground text-xs">{KIND_LABEL[item.kind]}</span>
      <StatusBadge
        variant={item.documentState === null ? "neutral" : STATE_VARIANT[item.documentState]}
        label={item.documentState === null ? "Unknown" : STATE_LABEL[item.documentState]}
        testId={`bank-accounts-detail__evidence-state-${item.id}`}
      />
      <span
        className="text-muted-foreground text-xs tabular-nums"
        data-testid={`bank-accounts-detail__evidence-size-${item.id}`}
      >
        {item.documentSizeBytes === null ? "—" : formatByteSize(item.documentSizeBytes)}
      </span>
      <span
        className="text-muted-foreground text-xs"
        title={dateTimeProvider.formatIsoToLocalDateTime(item.attachedAt)}
        data-testid={`bank-accounts-detail__evidence-attached-${item.id}`}
      >
        {dateTimeProvider.formatIsoToRelative(item.attachedAt)}
      </span>
      {href === null ? null : (
        <a
          href={href}
          // The read route sends `Content-Disposition: attachment` with no filename (the name is sealed server-side),
          // so the saved file is named here from the opened name; the browser strips path separators from it.
          download={item.documentName ?? true}
          className={cn(buttonVariants({ variant: "ghost", size: "sm" }))}
          aria-label="Download"
          title={`Download ${name}`}
          data-testid={`bank-accounts-detail__evidence-download-${item.id}`}
        >
          <Download className="size-3.5" aria-hidden="true" />
          Download
        </a>
      )}
    </li>
  );
}
