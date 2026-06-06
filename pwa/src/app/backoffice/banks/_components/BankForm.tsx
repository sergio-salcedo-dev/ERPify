"use client";

import { useEffect, useRef, useState } from "react";
import Link from "next/link";
import { useRouter } from "next/navigation";
import { container } from "@/context/shared/infrastructure/DependencyInjection/Container";
import { CreateBank } from "@/context/backoffice/bank/application/CreateBank";
import { UpdateBank } from "@/context/backoffice/bank/application/UpdateBank";
import { HttpError } from "@/context/shared/infrastructure/HttpClient/HttpError";
import type { ProblemDetails } from "@/context/shared/domain/ProblemDetails";
import { useZodForm } from "@/context/shared/infrastructure/Validation";
import {
  BANK_NAME_MAX_LENGTH,
  BankSchema,
  type BankFormValues,
} from "@/context/backoffice/bank/application/schemas/BankSchema";
import { PersistenceAction } from "@/context/shared/domain/types/status";
import { FormField, MutationError, SingleLineTextarea, Spinner } from "@/components/erpify";
import { BankProblemType } from "@/context/backoffice/bank/domain/BankProblemType";
import { toastNotifier } from "@/context/shared/infrastructure/Notification/Toast";
import { Button } from "@/components/ui/button";
import { buttonVariants } from "@/components/ui/button-variants";
import { Input } from "@/components/ui/input";
import { cn } from "@/lib/utils";
import { safeHref } from "@/lib/safeHref";
import { HttpStatus } from "@/context/shared/domain/types/http";
import { bankRoutes } from "../_lib/bankRoutes";

/** The two `PersistenceAction` values this form can be in. */
export type BankFormMode = typeof PersistenceAction.CREATING | typeof PersistenceAction.UPDATING;

interface BankFormInitial {
  id: string;
  name: string;
  shortName: string;
}

interface BankFormProps {
  mode: BankFormMode;
  initial?: BankFormInitial;
  /**
   * Edit-only recovery hook for a stale 404 (`bank-not-found`): the surface's
   * "Refresh" action calls this so the owning page can re-run its load and land
   * on the not-found empty state. Create mode never wires it.
   */
  onStaleBank?: () => void;
}

/** The n/255 counter appears once the name reaches 80% of the limit. */
const NAME_COUNTER_THRESHOLD = Math.floor(BANK_NAME_MAX_LENGTH * 0.8);

const BANK_FIELD_NAMES = ["name", "shortName"] as const;
type BankFieldName = (typeof BANK_FIELD_NAMES)[number];

function isBankFieldName(value: string): value is BankFieldName {
  return (BANK_FIELD_NAMES as readonly string[]).includes(value);
}

export function BankForm({ mode, initial, onStaleBank }: Readonly<BankFormProps>) {
  const router = useRouter();
  const [problem, setProblem] = useState<ProblemDetails | null>(null);

  // Hydration marker: until React attaches the submit handler, a click on
  // the submit button performs a NATIVE GET submission that leaks the form
  // values into the URL. The attribute lets QA (and any automation) wait for
  // the wired form instead of racing it.
  const formRef = useRef<HTMLFormElement>(null);
  useEffect(() => {
    formRef.current?.setAttribute("data-hydrated", "true");
  }, []);

  const {
    register,
    handleSubmit,
    setError,
    watch,
    formState: { errors, isSubmitting },
  } = useZodForm<BankFormValues>(BankSchema, {
    defaultValues: {
      name: initial?.name ?? "",
      shortName: initial?.shortName ?? "",
    },
  });

  const submitting = isSubmitting;
  const nameLength = (watch("name") ?? "").length;

  const handleHttpError = (err: HttpError) => {
    if (err.problem.status !== HttpStatus.BAD_REQUEST || !err.problem.violations) {
      setProblem(err.problem);
      return;
    }
    // Map server-side violations onto the same RHF errors object the
    // client validation populates, so the UI surfaces both via
    // `errors[name]?.message` without a parallel "violations" channel.
    let mappedAny = false;
    let unmappedExist = false;
    for (const violation of err.problem.violations) {
      if (isBankFieldName(violation.field)) {
        setError(violation.field, { type: "server", message: violation.message });
        mappedAny = true;
      } else {
        unmappedExist = true;
      }
    }
    if (!mappedAny || unmappedExist) {
      setProblem(err.problem);
      return;
    }
    // Every violation landed on a field: the outcome is fully represented in
    // the form, so any previously persistent surface must not survive it.
    setProblem(null);
  };

  const onSubmit = handleSubmit(async (values) => {
    // No eager clear here: a previous error stays visible while the retry is in
    // flight and is replaced only at the outcome — an explicit clear on
    // success, a new failure through `setProblem`.
    try {
      if (mode === PersistenceAction.CREATING) {
        const useCase = container.get<CreateBank>("BackOfficeCreateBank");
        const created = await useCase.run(values);
        setProblem(null);
        toastNotifier.success("Bank created", { description: created.name });
        router.push(safeHref(bankRoutes.detail(created.id)));
        router.refresh();
        return;
      }

      if (!initial) {
        throw new Error("BankForm in edit mode requires `initial`.");
      }

      const useCase = container.get<UpdateBank>("BackOfficeUpdateBank");
      const updated = await useCase.run(initial.id, values);
      setProblem(null);
      toastNotifier.success("Changes saved", { description: updated.name });
      router.push(safeHref(bankRoutes.detail(updated.id)));
      router.refresh();
    } catch (err) {
      if (!(err instanceof HttpError)) throw err;
      handleHttpError(err);
    }
  });

  const cancelHref = safeHref(
    mode === PersistenceAction.UPDATING && initial
      ? bankRoutes.detail(initial.id)
      : bankRoutes.list,
  );

  const isCreating = mode === PersistenceAction.CREATING;
  const submitLabelIdle = isCreating ? "Create bank" : "Save changes";

  // Typed recovery lives here (bank-specific component): a stale 404 on an edit
  // heals by re-running the page load via `onStaleBank`. Create mode never
  // offers a recovery action, whatever the problem type.
  const recoveryAction =
    !isCreating && problem?.type === BankProblemType.NOT_FOUND && onStaleBank ? (
      <Button
        type="button"
        variant="outline"
        size="sm"
        onClick={onStaleBank}
        aria-label="Refresh"
        title="Refresh this bank"
        data-testid="bank-form__mutation-error-refresh"
      >
        Refresh
      </Button>
    ) : undefined;

  return (
    <form
      ref={formRef}
      onSubmit={onSubmit}
      className="bank-form border-border bg-card space-y-4 rounded-lg border p-4"
      data-testid="bank-form"
      noValidate
    >
      {problem ? (
        <MutationError
          problem={problem}
          onDismiss={() => setProblem(null)}
          action={recoveryAction}
          testId="bank-form__mutation-error"
        />
      ) : null}

      <FormField
        name="name"
        label="Name"
        required
        error={errors.name?.message}
        helper={
          'Must be unique. Casing and accents are ignored — e.g. "BBVA" collides with "bbva", and "Sociedad Anónima" collides with "Sociedad Anonima".'
        }
      >
        <SingleLineTextarea
          {...register("name")}
          autoComplete="off"
          autoFocus={mode === PersistenceAction.CREATING}
          data-testid="bank-form__name"
        />
      </FormField>
      {nameLength >= NAME_COUNTER_THRESHOLD ? (
        <p
          className="bank-form__name-counter text-muted-foreground -mt-3 text-right text-xs tabular-nums"
          data-testid="bank-form__name-counter"
        >
          {nameLength}/{BANK_NAME_MAX_LENGTH}
        </p>
      ) : null}

      <FormField
        name="shortName"
        label="Short name"
        required
        error={errors.shortName?.message}
        helper={'Saved in upper-case ASCII without accents — e.g. "bbva" → "BBVA", "GLÉ" → "GLE".'}
      >
        <Input
          {...register("shortName")}
          autoComplete="off"
          style={{ textTransform: "uppercase" }}
          data-testid="bank-form__short-name"
        />
      </FormField>

      <footer className="bank-form__footer flex flex-col-reverse items-stretch gap-2 pt-2 sm:flex-row sm:items-center sm:justify-end">
        <Link
          href={cancelHref}
          aria-disabled={submitting || undefined}
          aria-label={
            mode === PersistenceAction.CREATING
              ? "Cancel and go back"
              : "Cancel and go back to bank"
          }
          title={
            mode === PersistenceAction.CREATING
              ? "Cancel and go back"
              : "Cancel and go back to bank"
          }
          tabIndex={submitting ? -1 : undefined}
          onClick={(event) => {
            if (submitting) event.preventDefault();
          }}
          className={cn(
            buttonVariants({ variant: "ghost", size: "sm" }),
            "w-full sm:w-auto",
            submitting && "pointer-events-none opacity-50",
          )}
        >
          Cancel
        </Link>
        <Button
          type="submit"
          size="sm"
          disabled={submitting}
          data-icon={submitting ? "inline-start" : undefined}
          aria-label={mode === PersistenceAction.CREATING ? "Create bank" : "Save bank changes"}
          title={mode === PersistenceAction.CREATING ? "Create bank" : "Save bank changes"}
          className="w-full sm:w-auto"
          data-testid="bank-form__submit"
        >
          {submitting ? (
            <>
              <Spinner className="size-3.5" testId="bank-form__submit-spinner" />
              Saving…
            </>
          ) : (
            submitLabelIdle
          )}
        </Button>
      </footer>
    </form>
  );
}
