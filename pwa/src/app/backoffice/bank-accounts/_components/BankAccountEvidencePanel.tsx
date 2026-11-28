"use client";

import { useCallback, useEffect, useRef, useState, type ChangeEvent } from "react";
import { Download, FilePlus2, X } from "lucide-react";
import {
  MutationError,
  ProblemDisplay,
  Spinner,
  StatusBadge,
  TruncatedText,
} from "@/components/erpify";
import { Button } from "@/components/ui/button";
import type { AttachBankAccountEvidence } from "@/context/backoffice/bankaccount/application/AttachBankAccountEvidence";
import type { DetachBankAccountEvidence } from "@/context/backoffice/bankaccount/application/DetachBankAccountEvidence";
import type { DownloadBankAccountEvidence } from "@/context/backoffice/bankaccount/application/DownloadBankAccountEvidence";
import type { ListBankAccountEvidence } from "@/context/backoffice/bankaccount/application/ListBankAccountEvidence";
import {
  isDownloadable,
  type BankAccountEvidence,
  type BankAccountEvidenceKind,
} from "@/context/backoffice/bankaccount/domain/BankAccountEvidence";
import {
  checkDocumentFile,
  MAX_DOCUMENT_MEGABYTES,
} from "@/context/backoffice/document/domain/checkDocumentFile";
import { saveBlob } from "@/context/backoffice/document/infrastructure/saveBlob";
import { useCan } from "@/context/shared/access/application/useCan";
import { Permission } from "@/context/shared/access/domain/Permission";
import { dateTimeProvider } from "@/context/shared/date-time-provider/infrastructure";
import { container } from "@/context/shared/dependency-injection/infrastructure/Container";
import type { ProblemDetails } from "@/context/shared/error/domain/ProblemDetails";
import { HttpError } from "@/context/shared/http-client/domain/HttpError";
import { uuidV7 } from "@/context/shared/uuid/infrastructure/uuidV7";
import {
  attachProblem,
  EVIDENCE_KIND_LABEL,
  EVIDENCE_STATE_LABEL,
  EVIDENCE_STATE_VARIANT,
  formatSize,
} from "../_lib/bankAccountEvidence";
import { DetachEvidenceButton } from "./DetachEvidenceButton";

/** The one kind this surface attaches today; the kind fixes the retention terms, never the user. */
const KIND: BankAccountEvidenceKind = "ownership-certificate";

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

function isAbort(error: unknown): boolean {
  return error instanceof DOMException && error.name === "AbortError";
}

interface Upload {
  readonly fileName: string;
  readonly percent: number;
  /** `sending` while the body leaves the browser and can be cancelled; `attaching` once it is filed. */
  readonly phase: "sending" | "attaching";
}

/**
 * The evidence a bank account holds: the documents the Documents upload filed, byte for byte, with
 * their state, and the controls that file, read and detach one. Shown only to a session holding
 * `document.custody` (the listing serves documents' names); the attach and the detach also need
 * `document.write` and `bankAccount.write`, the download `document.download`.
 *
 * It is the first screen offering both an image and an evidence entry point for one owner, so it
 * carries the misfiling guard of the conservation contract (ADR
 * `images-vs-documents-conservation-contract.md` D3): its own label and help say the file is kept
 * exactly as sent, as opposed to an image, which is re-encoded and loses its metadata.
 */
export function BankAccountEvidencePanel({ bankAccountId }: Readonly<{ bankAccountId: string }>) {
  const mayList = useCan(Permission.DOCUMENT_CUSTODY);
  if (!mayList) return null;
  return <EvidencePanel bankAccountId={bankAccountId} />;
}

function EvidencePanel({ bankAccountId }: Readonly<{ bankAccountId: string }>) {
  const mayFile = useCan(Permission.DOCUMENT_WRITE);
  const mayLink = useCan(Permission.BANK_ACCOUNT_WRITE);
  const mayAttach = mayFile && mayLink;
  const mayDownload = useCan(Permission.DOCUMENT_DOWNLOAD);
  const inputRef = useRef<HTMLInputElement>(null);
  const abortRef = useRef<AbortController | null>(null);
  const [items, setItems] = useState<BankAccountEvidence[] | null>(null);
  const [loadProblem, setLoadProblem] = useState<ProblemDetails | null>(null);
  const [upload, setUpload] = useState<Upload | null>(null);
  const [refusal, setRefusal] = useState<string | null>(null);
  const [actionProblem, setActionProblem] = useState<ProblemDetails | null>(null);
  const [busyItem, setBusyItem] = useState<string | null>(null);
  const [status, setStatus] = useState("");

  const load = useCallback(async (): Promise<void> => {
    try {
      const listed = await container
        .get<ListBankAccountEvidence>("BackOfficeListBankAccountEvidence")
        .run(bankAccountId);
      setItems(listed);
      setLoadProblem(null);
    } catch (error) {
      setLoadProblem(asProblem(error));
    }
  }, [bankAccountId]);

  useEffect(() => {
    // Fire-and-forget: `load` reports its own failure.
    // eslint-disable-next-line react-hooks/set-state-in-effect
    void load();
  }, [load]);

  useEffect(() => () => abortRef.current?.abort(), []);

  async function attach(file: File): Promise<void> {
    const controller = new AbortController();
    abortRef.current = controller;
    setUpload({ fileName: file.name, percent: 0, phase: "sending" });
    setActionProblem(null);
    setStatus(`Uploading ${file.name}.`);
    try {
      await container
        .get<AttachBankAccountEvidence>("BackOfficeAttachBankAccountEvidence")
        .run(bankAccountId, file, KIND, {
          signal: controller.signal,
          onProgress: ({ loaded, total }) =>
            setUpload({
              fileName: file.name,
              percent: total > 0 ? Math.min(100, Math.floor((loaded / total) * 100)) : 0,
              phase: "sending",
            }),
          onFiled: () => {
            abortRef.current = null;
            setUpload({ fileName: file.name, percent: 100, phase: "attaching" });
            setStatus(`${file.name} uploaded; attaching it.`);
          },
        });
      setStatus(`${file.name} attached as evidence.`);
      await load();
    } catch (error) {
      if (isAbort(error)) {
        setStatus("Upload cancelled.");
        return;
      }
      setStatus("");
      setActionProblem(attachProblem(asProblem(error), file.name));
      // The upload may have filed a document, or the attach may have landed before the answer was
      // lost: the list is what says which.
      await load();
    } finally {
      abortRef.current = null;
      setUpload(null);
    }
  }

  function handlePick(event: ChangeEvent<HTMLInputElement>): void {
    const file = event.target.files?.[0];
    event.target.value = "";
    if (!file || upload !== null) return;
    const check = checkDocumentFile(file);
    if (!check.ok) {
      setRefusal(check.message);
      return;
    }
    setRefusal(null);
    void attach(file);
  }

  async function download(evidence: BankAccountEvidence): Promise<void> {
    setBusyItem(evidence.id);
    setActionProblem(null);
    try {
      const saved = await container
        .get<DownloadBankAccountEvidence>("BackOfficeDownloadBankAccountEvidence")
        .run(evidence);
      saveBlob(saved.blob, saved.filename);
      setStatus(`${saved.filename} downloaded.`);
    } catch (error) {
      setActionProblem(asProblem(error));
    } finally {
      setBusyItem(null);
    }
  }

  async function detach(evidence: BankAccountEvidence): Promise<void> {
    setBusyItem(evidence.id);
    setActionProblem(null);
    try {
      await container
        .get<DetachBankAccountEvidence>("BackOfficeDetachBankAccountEvidence")
        .run(bankAccountId, evidence.id);
      setStatus(`${evidence.name ?? "The document"} detached.`);
    } catch (error) {
      setActionProblem(asProblem(error));
    } finally {
      setBusyItem(null);
      await load();
    }
  }

  const busy = upload !== null || busyItem !== null;

  return (
    <section
      className="bank-account-evidence border-border bg-card space-y-3 rounded-lg border p-4"
      aria-labelledby="bank-account-evidence-heading"
      data-testid="bank-account-evidence"
    >
      <div className="bank-account-evidence__header flex flex-col gap-2 sm:flex-row sm:items-start sm:justify-between">
        <div className="min-w-0 space-y-1">
          <h2
            id="bank-account-evidence-heading"
            className="text-foreground text-sm font-semibold"
            data-testid="bank-account-evidence__title"
          >
            Evidence
          </h2>
          <p className="text-muted-foreground text-xs" data-testid="bank-account-evidence__help">
            {`Files kept exactly as sent, as proof: the original bytes are stored with their digest, scanned, and never re-encoded. Attach the bank's ownership certificate here — any format, up to ${MAX_DOCUMENT_MEGABYTES} MB. A picture of the account is an image, not evidence: images are re-encoded and lose their metadata.`}
          </p>
        </div>
        {mayAttach ? (
          <div className="flex flex-none items-center gap-2">
            <input
              ref={inputRef}
              type="file"
              hidden
              disabled={upload !== null}
              onChange={handlePick}
              data-testid="bank-account-evidence__input"
            />
            <Button
              type="button"
              variant="outline"
              size="sm"
              disabled={upload !== null}
              aria-label="Attach evidence"
              title={`Attach evidence: ${EVIDENCE_KIND_LABEL[KIND].toLowerCase()}`}
              onClick={() => inputRef.current?.click()}
              data-testid="bank-account-evidence__attach"
            >
              {upload === null ? <FilePlus2 className="size-4" aria-hidden="true" /> : <Spinner />}
              Attach evidence
            </Button>
          </div>
        ) : null}
      </div>

      {/* Outside the busy subtree, so its announcements are not held back while the panel works. */}
      <p
        className="sr-only"
        role="status"
        aria-live="polite"
        data-testid="bank-account-evidence__status"
      >
        {status}
      </p>

      <div className="bank-account-evidence__body space-y-3" aria-busy={busy}>
        {upload === null ? null : (
          <div
            className="bank-account-evidence__upload flex flex-wrap items-center gap-3"
            data-testid="bank-account-evidence__upload"
          >
            <span className="text-muted-foreground min-w-0 truncate text-sm">
              {upload.phase === "sending"
                ? `Uploading ${upload.fileName}`
                : `Attaching ${upload.fileName}`}
            </span>
            <progress
              className="bank-account-evidence__progress h-2 w-40"
              max={100}
              value={upload.percent}
              aria-label="Upload progress"
              data-testid="bank-account-evidence__progress"
            />
            <span className="text-muted-foreground text-xs tabular-nums">{`${upload.percent}%`}</span>
            {upload.phase === "sending" ? (
              <Button
                type="button"
                variant="ghost"
                size="sm"
                aria-label="Cancel upload"
                title={`Cancel the upload of ${upload.fileName}`}
                onClick={() => abortRef.current?.abort()}
                data-testid="bank-account-evidence__cancel"
              >
                <X className="size-4" aria-hidden="true" />
                Cancel
              </Button>
            ) : null}
          </div>
        )}

        {refusal === null ? null : (
          <p
            role="alert"
            className="text-destructive text-sm"
            data-testid="bank-account-evidence__refusal"
          >
            {refusal}
          </p>
        )}

        {actionProblem === null ? null : (
          <MutationError
            problem={actionProblem}
            onDismiss={() => setActionProblem(null)}
            testId="bank-account-evidence__error"
          />
        )}

        {loadProblem === null ? null : (
          <div data-testid="bank-account-evidence__load-error">
            <ProblemDisplay problem={loadProblem} />
          </div>
        )}

        {items === null && loadProblem === null ? (
          <p className="text-muted-foreground text-sm" data-testid="bank-account-evidence__loading">
            Loading evidence…
          </p>
        ) : null}

        {items !== null && items.length === 0 ? (
          <p className="text-muted-foreground text-sm" data-testid="bank-account-evidence__empty">
            No evidence attached yet.
          </p>
        ) : null}

        {items !== null && items.length > 0 ? (
          <ul
            className="bank-account-evidence__list divide-border divide-y"
            data-testid="bank-account-evidence__list"
          >
            {items.map((evidence) => (
              <li
                key={evidence.id}
                className="bank-account-evidence__item flex flex-col gap-1 py-2 sm:flex-row sm:items-center sm:justify-between"
                data-testid={`bank-account-evidence__item-${evidence.id}`}
              >
                <div className="min-w-0 space-y-0.5">
                  <TruncatedText
                    value={evidence.name ?? "Name unavailable"}
                    className="text-foreground text-sm font-medium"
                    testId={`bank-account-evidence__name-${evidence.id}`}
                  />
                  <p className="text-muted-foreground flex flex-wrap items-center gap-x-2 text-xs">
                    <span>{EVIDENCE_KIND_LABEL[evidence.kind]}</span>
                    <span>{formatSize(evidence.sizeBytes)}</span>
                    <span title={dateTimeProvider.formatIsoToLocalDateTime(evidence.attachedAt)}>
                      {`Attached ${dateTimeProvider.formatIsoToRelative(evidence.attachedAt)}`}
                    </span>
                  </p>
                </div>
                <div className="flex flex-none items-center gap-2">
                  <StatusBadge
                    variant={EVIDENCE_STATE_VARIANT[evidence.state]}
                    label={EVIDENCE_STATE_LABEL[evidence.state]}
                    testId={`bank-account-evidence__state-${evidence.id}`}
                  />
                  {mayDownload && isDownloadable(evidence) ? (
                    <Button
                      type="button"
                      variant="outline"
                      size="sm"
                      disabled={busy}
                      aria-label="Download"
                      title={`Download ${evidence.name ?? "document"}`}
                      onClick={() => void download(evidence)}
                      data-testid={`bank-account-evidence__download-${evidence.id}`}
                    >
                      {busyItem === evidence.id ? (
                        <Spinner />
                      ) : (
                        <Download className="size-4" aria-hidden="true" />
                      )}
                      Download
                    </Button>
                  ) : null}
                  {mayAttach ? (
                    <DetachEvidenceButton
                      evidenceId={evidence.id}
                      name={evidence.name}
                      disabled={busy}
                      onConfirm={() => detach(evidence)}
                    />
                  ) : null}
                </div>
              </li>
            ))}
          </ul>
        ) : null}
      </div>
    </section>
  );
}
