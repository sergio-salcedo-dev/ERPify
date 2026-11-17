"use client";

import { useMemo, useRef, useState, type ChangeEvent } from "react";
import { ImagePlus, Trash2 } from "lucide-react";
import { Button } from "@/components/ui/button";
import { cn } from "@/components/cn";
import type { ProblemDetails } from "@/context/shared/error/domain/ProblemDetails";
import { NETWORK_ERROR, REQUEST_TIMEOUT } from "@/context/shared/http-client/domain/HttpClient";
import { HttpError } from "@/context/shared/http-client/domain/HttpError";
import { checkImageFile } from "@/context/shared/images/domain/checkImageFile";
import {
  ImageAction,
  imageProblemMessage,
} from "@/context/shared/images/domain/imageProblemMessage";
import {
  IMAGE_ACCEPT_ATTRIBUTE,
  MAX_IMAGE_MEGABYTES,
} from "@/context/shared/images/domain/imageLimits";
import { uuidV7 } from "@/context/shared/uuid/infrastructure/uuidV7";
import { ImageDisplay } from "./ImageDisplay";
import { announce } from "./liveAnnouncer";
import { MutationError } from "./MutationError";
import { Spinner } from "./Spinner";

interface ImageUploadFieldProps {
  /** The id the owner holds now, or `null`/`undefined` when it holds none. */
  imageId: string | null | undefined;
  /** Called with the new id after a set or replace, and with `null` after a removal. */
  onImageChange: (imageId: string | null) => void;
  /** Writes the file as the owner's image and resolves with the id the owner now holds. */
  onUpload: (file: File) => Promise<{ readonly imageId: string }>;
  /** Removes the owner's image. */
  onRemove: () => Promise<void>;
  /** The owner's image by name, e.g. "Bank logo" — the accessible name of the picker and preview. */
  label: string;
  /** Disables every action, e.g. when the caller may read the image but not change it. */
  disabled?: boolean;
  className?: string;
  /** Consumer-owned test id prefix; derives `__preview`, `__pick`, `__input`, `__remove`, … */
  testIdPrefix: string;
}

/** What a failed attempt keeps, so a retry repeats exactly it. */
type Attempt = { readonly kind: "upload"; readonly file: File } | { readonly kind: "remove" };

interface Failure {
  readonly problem: ProblemDetails;
  readonly attempt: Attempt;
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

/** The two outcomes where the request may or may not have reached the server. */
function outcomeIsUnknown(problem: ProblemDetails): boolean {
  return problem.type === NETWORK_ERROR || problem.type === REQUEST_TIMEOUT;
}

function failureDetail({ problem, attempt }: Failure): string {
  if (attempt.kind === "upload") {
    return outcomeIsUnknown(problem)
      ? `It is not known whether "${attempt.file.name}" was uploaded. Reload to see the image on file; the file is kept so you can try again.`
      : `"${attempt.file.name}" was not uploaded. It is kept so you can try again; the image on file is unchanged.`;
  }
  return outcomeIsUnknown(problem)
    ? "It is not known whether the image was removed. Reload to see the image on file."
    : "The image was not removed. The image on file is unchanged.";
}

function describeFailure(failure: Failure): ProblemDetails {
  const action = failure.attempt.kind === "upload" ? ImageAction.SAVE : ImageAction.REMOVE;
  return {
    ...failure.problem,
    title: imageProblemMessage(failure.problem, action),
    detail: failureDetail(failure),
  };
}

/**
 * The upload control of an owner's single image: the current image, a picker, and a remove when
 * one is held.
 *
 * It owns the interaction and not the transport: it receives async callbacks, because no
 * `components/erpify` piece reaches the container. A file is checked before any byte is sent
 * (empty, outside the allowlist, over the cap) and a refusal is an inline message with the field
 * still usable. A server refusal is a persistent `<MutationError>` in the English of the
 * problem's `type`, naming the file kept, with a retry. A second pick while one is in flight is
 * ignored and the control reads busy.
 */
export function ImageUploadField({
  imageId,
  onImageChange,
  onUpload,
  onRemove,
  label,
  disabled = false,
  className,
  testIdPrefix,
}: Readonly<ImageUploadFieldProps>) {
  const inputRef = useRef<HTMLInputElement>(null);
  const inFlight = useRef(false);
  const [busy, setBusy] = useState<"upload" | "remove" | null>(null);
  const [refusal, setRefusal] = useState<string | null>(null);
  const [failure, setFailure] = useState<Failure | null>(null);

  // Stable per failure: MutationError re-focuses its container whenever `problem` changes identity,
  // so a fresh object on every render would pull focus back on any unrelated re-render.
  const failureProblem = useMemo(
    () => (failure === null ? null : describeFailure(failure)),
    [failure],
  );

  const holdsImage = typeof imageId === "string" && imageId !== "";

  async function run(attempt: Attempt): Promise<void> {
    if (inFlight.current) return;
    inFlight.current = true;
    setBusy(attempt.kind);
    setRefusal(null);
    setFailure(null);
    try {
      if (attempt.kind === "upload") {
        const uploaded = await onUpload(attempt.file);
        onImageChange(uploaded.imageId);
        announce(`${label} saved.`);
      } else {
        await onRemove();
        onImageChange(null);
        announce(`${label} removed.`);
      }
    } catch (error) {
      setFailure({ problem: asProblem(error), attempt });
    } finally {
      inFlight.current = false;
      setBusy(null);
    }
  }

  function handlePick(event: ChangeEvent<HTMLInputElement>): void {
    const file = event.target.files?.[0];
    // Cleared so picking the same file again after a failure raises `change` once more.
    event.target.value = "";
    if (!file || inFlight.current) return;
    const check = checkImageFile(file);
    if (!check.ok) {
      setFailure(null);
      setRefusal(check.message);
      return;
    }
    void run({ kind: "upload", file });
  }

  const inactive = disabled || busy !== null;

  return (
    <div
      className={cn("image-upload-field flex flex-col gap-3", className)}
      data-testid={testIdPrefix}
      aria-busy={busy !== null}
    >
      <div className="image-upload-field__row flex flex-wrap items-center gap-3">
        <ImageDisplay
          imageId={imageId}
          alt={label}
          className="size-16"
          testId={`${testIdPrefix}__preview`}
        />
        <input
          ref={inputRef}
          type="file"
          accept={IMAGE_ACCEPT_ATTRIBUTE}
          hidden
          disabled={inactive}
          onChange={handlePick}
          data-testid={`${testIdPrefix}__input`}
        />
        <Button
          type="button"
          variant="outline"
          size="sm"
          disabled={inactive}
          aria-label={holdsImage ? "Replace" : "Upload"}
          title={`${holdsImage ? "Replace" : "Upload"} ${label.toLowerCase()}`}
          onClick={() => inputRef.current?.click()}
          data-testid={`${testIdPrefix}__pick`}
        >
          {busy === "upload" ? <Spinner /> : <ImagePlus className="size-4" aria-hidden="true" />}
          {holdsImage ? `Replace ${label.toLowerCase()}` : `Upload ${label.toLowerCase()}`}
        </Button>
        {holdsImage ? (
          <Button
            type="button"
            variant="ghost"
            size="sm"
            disabled={inactive}
            aria-label="Remove"
            title={`Remove ${label.toLowerCase()}`}
            onClick={() => void run({ kind: "remove" })}
            data-testid={`${testIdPrefix}__remove`}
          >
            {busy === "remove" ? <Spinner /> : <Trash2 className="size-4" aria-hidden="true" />}
            {`Remove ${label.toLowerCase()}`}
          </Button>
        ) : null}
        {busy !== null ? (
          <span className="text-muted-foreground text-sm" data-testid={`${testIdPrefix}__busy`}>
            {busy === "upload" ? "Uploading…" : "Removing…"}
          </span>
        ) : null}
      </div>
      <p className="text-muted-foreground text-xs">
        {`JPEG, PNG, WebP or GIF, up to ${MAX_IMAGE_MEGABYTES} MB.`}
      </p>
      {refusal === null ? null : (
        <p
          role="alert"
          className="text-destructive text-sm"
          data-testid={`${testIdPrefix}__refusal`}
        >
          {refusal}
        </p>
      )}
      {failure === null || failureProblem === null ? null : (
        <MutationError
          problem={failureProblem}
          onDismiss={() => setFailure(null)}
          action={
            <Button
              type="button"
              variant="outline"
              size="sm"
              disabled={inactive}
              aria-label="Try again"
              title={`Try again: ${label.toLowerCase()}`}
              onClick={() => void run(failure.attempt)}
              data-testid={`${testIdPrefix}__retry`}
            >
              Try again
            </Button>
          }
          testId={`${testIdPrefix}__error`}
        />
      )}
    </div>
  );
}
