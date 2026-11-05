"use client";

import { useRef, useState, type FormEvent, type ReactNode } from "react";
import { ShieldCheck } from "lucide-react";
import { FormField, Spinner } from "@/components/erpify";
import { Button } from "@/components/ui/button";
import { PasswordInput } from "@/components/ui/PasswordInput";
import {
  Dialog,
  DialogClose,
  DialogContent,
  DialogDescription,
  DialogFooter,
  DialogHeader,
  DialogTitle,
} from "@/components/ui/dialog";
import { HttpError } from "@/context/shared/http-client/domain/HttpError";
import { UserProblemType } from "@/context/backoffice/user/domain/UserProblemType";
import { useZodForm } from "@/context/shared/validation/infrastructure";
import {
  ConfirmCurrentPasswordSchema,
  type ConfirmCurrentPasswordFormValues,
} from "@/context/backoffice/user/application/schemas/auth/ConfirmCurrentPasswordSchema";

/** True for the one failure this dialog owns: the password typed here did not match the caller's. */
export function isInvalidCurrentPassword(error: unknown): boolean {
  return (
    error instanceof HttpError && error.problem.type === UserProblemType.INVALID_CURRENT_PASSWORD
  );
}

interface CurrentPasswordDialogProps {
  open: boolean;
  onOpenChange: (open: boolean) => void;
  title: string;
  description: ReactNode;
  /** Label of the confirming button, which is also its accessible name. */
  confirmLabel: string;
  /** The surface owning the dialog; every testid here is `<prefix>__<element>`. */
  testIdPrefix: string;
  /**
   * Runs the write with the typed password. Resolving closes the dialog — whether the write succeeded or the
   * owner has already surfaced its failure in its persistent error surface. Rejecting with a wrong-password
   * `HttpError` (see {@link isInvalidCurrentPassword}) keeps the dialog open with the message on the field; any
   * other rejection is a defect and goes to the segment error boundary.
   */
  onConfirm: (currentPassword: string) => Promise<void>;
}

/**
 * The confirmation step in front of a write that changes who may do what. It collects the CALLER's current
 * password and hands it to the owner, so a borrowed session cannot re-grant roles or retire an identity on its
 * own. A wrong password is a field error here and the dialog stays open for another try; every other failure
 * belongs to the owner's persistent `<MutationError>` (the dialog closes itself, per the action-button
 * contract), including a spent proof budget (429).
 *
 * The form lives in a child that mounts only while the dialog is open, so the typed credential exists in
 * exactly one component's state for exactly as long as the dialog is on screen: every exit — cancel, Esc, an
 * overlay click, success — discards it. The same mount is why no unhydrated form can exist to perform a
 * native GET carrying the password in the URL.
 */
export function CurrentPasswordDialog({
  open,
  onOpenChange,
  title,
  description,
  confirmLabel,
  testIdPrefix,
  onConfirm,
}: Readonly<CurrentPasswordDialogProps>) {
  // Dismissing mid-flight would orphan an attempt that has already spent a unit of the per-identity proof
  // budget — the only ceiling on guessing this password from a live session — so the dialog holds until the
  // request settles.
  const busyRef = useRef(false);

  return (
    <Dialog
      open={open}
      onOpenChange={(next: boolean) => {
        if (!next && busyRef.current) return;
        onOpenChange(next);
      }}
    >
      <DialogContent className="sm:max-w-md" data-testid={`${testIdPrefix}__dialog`}>
        <PasswordProofForm
          title={title}
          description={description}
          confirmLabel={confirmLabel}
          testIdPrefix={testIdPrefix}
          busyRef={busyRef}
          onConfirm={onConfirm}
          onDone={() => onOpenChange(false)}
        />
      </DialogContent>
    </Dialog>
  );
}

function PasswordProofForm({
  title,
  description,
  confirmLabel,
  testIdPrefix,
  busyRef,
  onConfirm,
  onDone,
}: Readonly<
  Pick<
    CurrentPasswordDialogProps,
    "title" | "description" | "confirmLabel" | "testIdPrefix" | "onConfirm"
  > & { busyRef: { current: boolean }; onDone: () => void }
>) {
  // A failure that is not a wrong password is not this form's to explain. Parking it here and re-throwing
  // during render hands it to the segment error boundary; throwing from the submit callback would only
  // reject a promise nobody awaits and the button would appear to do nothing.
  const [fatal, setFatal] = useState<unknown>(null);

  const {
    register,
    handleSubmit,
    setError,
    formState: { errors, isSubmitting },
  } = useZodForm<ConfirmCurrentPasswordFormValues>(ConfirmCurrentPasswordSchema, {
    defaultValues: { currentPassword: "" },
  });

  const submit = handleSubmit(async ({ currentPassword }) => {
    try {
      await onConfirm(currentPassword);
      onDone();
    } catch (error) {
      if (error instanceof HttpError && isInvalidCurrentPassword(error)) {
        setError(
          "currentPassword",
          { type: "server", message: error.problem.detail ?? error.problem.title },
          { shouldFocus: true },
        );
        return;
      }
      setFatal(() => error);
    }
  });

  // In-flight latch: `disabled` lands on the next React commit, so holding Enter in the password field
  // presents the same password twice before the button greys out, spending a second unit of the budget.
  const onSubmit = (event: FormEvent<HTMLFormElement>) => {
    if (busyRef.current) {
      event.preventDefault();
      return;
    }
    busyRef.current = true;
    // `handleSubmit` settles after a validation reject too, so releasing here rather than inside the async
    // body also frees a submit that never reached the owner.
    return submit(event).finally(() => {
      busyRef.current = false;
    });
  };

  if (fatal !== null) throw fatal;

  return (
    <form onSubmit={onSubmit} className="flex flex-col gap-4" noValidate>
      <DialogHeader>
        <div className="flex items-start gap-3">
          <span
            className="bg-primary/10 text-primary flex size-10 shrink-0 items-center justify-center rounded-full"
            aria-hidden="true"
          >
            <ShieldCheck className="size-5" />
          </span>
          <div className="flex flex-1 flex-col gap-2">
            <DialogTitle className="text-lg">{title}</DialogTitle>
            <DialogDescription className="text-base leading-relaxed">
              {description}
            </DialogDescription>
          </div>
        </div>
      </DialogHeader>

      <FormField
        name="currentPassword"
        label="Your current password"
        required
        error={errors.currentPassword?.message}
        helper="This change asks for your password, so a borrowed session cannot make it."
      >
        <PasswordInput
          autoComplete="current-password"
          defaultRevealed={false}
          toggleTestId={`${testIdPrefix}__password-toggle`}
          {...register("currentPassword")}
          data-testid={`${testIdPrefix}__password`}
        />
      </FormField>

      <DialogFooter>
        <DialogClose
          render={
            <Button
              type="button"
              variant="ghost"
              disabled={isSubmitting}
              aria-label="Cancel"
              title="Cancel"
              data-testid={`${testIdPrefix}__cancel`}
            >
              Cancel
            </Button>
          }
        />
        <Button
          type="submit"
          disabled={isSubmitting}
          data-icon={isSubmitting ? "inline-start" : undefined}
          aria-label={confirmLabel}
          title={confirmLabel}
          data-testid={`${testIdPrefix}__confirm`}
        >
          {isSubmitting ? (
            <>
              <Spinner className="size-4" testId={`${testIdPrefix}__confirm-spinner`} />
              Working…
            </>
          ) : (
            confirmLabel
          )}
        </Button>
      </DialogFooter>
    </form>
  );
}
