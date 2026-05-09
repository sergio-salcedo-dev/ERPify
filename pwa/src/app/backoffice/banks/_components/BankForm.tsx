"use client";

import { useState } from "react";
import Link from "next/link";
import { useRouter } from "next/navigation";
import { container } from "@/context/shared/infrastructure/DependencyInjection/Container";
import { CreateBank } from "@/context/backoffice/bank/application/CreateBank";
import { UpdateBank } from "@/context/backoffice/bank/application/UpdateBank";
import { HttpError } from "@/context/shared/infrastructure/HttpClient/HttpError";
import type { ProblemDetails } from "@/context/shared/domain/ProblemDetails";
import { useZodForm } from "@/context/shared/infrastructure/Validation";
import {
  BankSchema,
  type BankFormValues,
} from "@/context/backoffice/bank/application/schemas/BankSchema";
import { PersistenceAction } from "@/context/shared/domain/types/status";
import { FormField, ProblemDisplay } from "@/components/erpify";
import { Button, buttonVariants } from "@/components/ui/button";
import { Input } from "@/components/ui/input";
import { cn } from "@/lib/utils";
import { safeHref } from "@/lib/safeHref";
import { HttpStatus } from "@/context/shared/domain/types/http";

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
}

const BANK_FIELD_NAMES = ["name", "shortName"] as const;
type BankFieldName = (typeof BANK_FIELD_NAMES)[number];

function isBankFieldName(value: string): value is BankFieldName {
  return (BANK_FIELD_NAMES as readonly string[]).includes(value);
}

export function BankForm({ mode, initial }: BankFormProps) {
  const router = useRouter();
  const [problem, setProblem] = useState<ProblemDetails | null>(null);

  const {
    register,
    handleSubmit,
    setError,
    formState: { errors, isSubmitting },
  } = useZodForm<BankFormValues>(BankSchema, {
    defaultValues: {
      name: initial?.name ?? "",
      shortName: initial?.shortName ?? "",
    },
  });

  const submitting = isSubmitting;

  const onSubmit = handleSubmit(async (values) => {
    setProblem(null);
    try {
      if (mode === PersistenceAction.CREATING) {
        const useCase = container.get<CreateBank>("BackOfficeCreateBank");
        const created = await useCase.run(values);
        router.push(safeHref(`/backoffice/banks/${encodeURIComponent(created.id)}`));
        router.refresh();
        return;
      }

      if (!initial) {
        throw new Error("BankForm in edit mode requires `initial`.");
      }

      const useCase = container.get<UpdateBank>("BackOfficeUpdateBank");
      const updated = await useCase.run(initial.id, values);
      router.push(safeHref(`/backoffice/banks/${encodeURIComponent(updated.id)}`));
      router.refresh();
    } catch (err) {
      if (!(err instanceof HttpError)) throw err;

      if (err.problem.status === HttpStatus.BAD_REQUEST && err.problem.violations) {
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
        }
        return;
      }

      setProblem(err.problem);
    }
  });

  const cancelHref = safeHref(
    mode === PersistenceAction.UPDATING && initial
      ? `/backoffice/banks/${encodeURIComponent(initial.id)}`
      : "/backoffice/banks",
  );

  return (
    <form
      onSubmit={onSubmit}
      className="bank-form border-border bg-card space-y-4 rounded-lg border p-4"
      data-testid="bank-form"
      noValidate
    >
      {problem ? <ProblemDisplay problem={problem} variant="inline" /> : null}

      <FormField name="name" label="Name" required error={errors.name?.message}>
        <Input
          {...register("name")}
          maxLength={255}
          autoComplete="off"
          autoFocus={mode === PersistenceAction.CREATING}
          data-testid="bank-form__name"
        />
      </FormField>

      <FormField name="shortName" label="Short name" required error={errors.shortName?.message}>
        <Input
          {...register("shortName")}
          maxLength={50}
          autoComplete="off"
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
          aria-label={mode === PersistenceAction.CREATING ? "Create bank" : "Save bank changes"}
          title={mode === PersistenceAction.CREATING ? "Create bank" : "Save bank changes"}
          className="w-full sm:w-auto"
          data-testid="bank-form__submit"
        >
          {submitting
            ? "Saving…"
            : mode === PersistenceAction.CREATING
              ? "Create bank"
              : "Save changes"}
        </Button>
      </footer>
    </form>
  );
}
