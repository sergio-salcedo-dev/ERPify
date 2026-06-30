"use client";

import { useEffect, useRef, useState } from "react";
import Link from "next/link";
import { useRouter } from "next/navigation";
import { container } from "@/context/shared/dependency-injection/infrastructure/Container";
import { CreateBankAccount } from "@/context/backoffice/bankaccount/application/CreateBankAccount";
import { UpdateBankAccount } from "@/context/backoffice/bankaccount/application/UpdateBankAccount";
import type { BankAccountCurrency } from "@/context/backoffice/bankaccount/domain/BankAccount";
import { BankAccountProblemType } from "@/context/backoffice/bankaccount/domain/BankAccountProblemType";
import { HttpError } from "@/context/shared/http-client/domain/HttpError";
import { HttpStatus } from "@/context/shared/http-client/domain/HttpStatus";
import type { ProblemDetails } from "@/context/shared/error/domain/ProblemDetails";
import { useZodForm } from "@/context/shared/validation/infrastructure";
import {
  BANK_ACCOUNT_CURRENCIES,
  BankAccountSchema,
  type BankAccountFormValues,
} from "@/context/backoffice/bankaccount/application/schemas/BankAccountSchema";
import { PersistenceAction } from "@/context/shared/view-state/domain/ViewState";
import { FormField, MutationError, SingleLineTextarea, Spinner } from "@/components/erpify";
import { toastNotifier } from "@/context/shared/notification/infrastructure/Toast";
import { Button } from "@/components/ui/button";
import { buttonVariants } from "@/components/ui/button-variants";
import { Input } from "@/components/ui/input";
import { cn } from "@/components/cn";
import { safeHref } from "@/context/shared/navigation/domain/safeHref";
import { bankAccountRoutes } from "../_lib/bankAccountRoutes";

/** The two `PersistenceAction` values this form can be in. */
export type BankAccountFormMode =
  | typeof PersistenceAction.CREATING
  | typeof PersistenceAction.UPDATING;

interface BankAccountFormInitial {
  id: string;
  holderName: string;
  iban: string;
  bic: string | null;
  alias: string | null;
  currency: BankAccountCurrency;
}

interface BankAccountFormProps {
  mode: BankAccountFormMode;
  /** Owning bank id — from the route; sent in the create body, never on update. */
  bankId: string;
  initial?: BankAccountFormInitial;
  /**
   * Edit-only recovery hook for a stale 404 (`bank-account-not-found`): the
   * surface's "Refresh" action calls this so the owning page re-runs its load
   * and lands on the not-found empty state. Create mode never wires it.
   */
  onStaleAccount?: () => void;
}

/** Presentation labels keyed by the wire enum identity (never the raw code to the user). */
const CURRENCY_LABEL: Record<BankAccountCurrency, string> = {
  EUR: "Euro (EUR)",
};

const SELECT_CLASS =
  "border-border bg-background text-foreground focus-visible:ring-ring h-9 w-full rounded-md border px-2 text-sm focus-visible:ring-2 focus-visible:outline-none";

const ACCOUNT_FIELD_NAMES = ["holderName", "iban", "bic", "alias", "currency"] as const;
type AccountFieldName = (typeof ACCOUNT_FIELD_NAMES)[number];
function isAccountFieldName(value: string): value is AccountFieldName {
  return (ACCOUNT_FIELD_NAMES as readonly string[]).includes(value);
}

export function BankAccountForm({
  mode,
  bankId,
  initial,
  onStaleAccount,
}: Readonly<BankAccountFormProps>) {
  const router = useRouter();
  const [problem, setProblem] = useState<ProblemDetails | null>(null);
  const isCreating = mode === PersistenceAction.CREATING;

  // Hydration marker: until React attaches the submit handler, a click on the
  // submit button performs a NATIVE GET submission that would leak the form
  // values into the URL. QA waits on this attribute instead of racing it.
  const formRef = useRef<HTMLFormElement>(null);
  useEffect(() => {
    formRef.current?.setAttribute("data-hydrated", "true");
  }, []);

  const {
    register,
    handleSubmit,
    setError,
    formState: { errors, isSubmitting },
  } = useZodForm<BankAccountFormValues>(BankAccountSchema, {
    defaultValues: {
      holderName: initial?.holderName ?? "",
      iban: initial?.iban ?? "",
      bic: initial?.bic ?? "",
      alias: initial?.alias ?? "",
      currency: initial?.currency ?? "EUR",
    },
  });

  const submitting = isSubmitting;

  const handleHttpError = (err: HttpError): void => {
    // Per-field violations arrive on 422 (`validation-failed`, incl. the unique
    // IBAN and the BIC↔IBAN coherence) and on 400 (binding-level); both map onto
    // their field. Anything else (500 / network / a 422 with no violations) is a
    // whole-surface failure.
    const status = err.problem.status;
    const isFieldViolation =
      status === HttpStatus.UNPROCESSABLE_ENTITY || status === HttpStatus.BAD_REQUEST;
    if (!isFieldViolation || !err.problem.violations) {
      setProblem(err.problem);
      return;
    }
    // Map server-side violations onto the same RHF errors object the client
    // validation populates, so the UI surfaces both via `errors[name]?.message`.
    let mappedAny = false;
    let unmappedExist = false;
    for (const violation of err.problem.violations) {
      if (isAccountFieldName(violation.field)) {
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
    setProblem(null);
  };

  const onSubmit = handleSubmit(async (values) => {
    // `bic`/`alias` collapse an empty string to null (clearing the optional);
    // the IBAN is already canonicalised by the schema. No eager clear here: a
    // previous error stays visible during the retry and is replaced only at the
    // outcome (an explicit clear on success, a new failure through setProblem).
    const bic = values.bic && values.bic !== "" ? values.bic.toUpperCase() : null;
    const alias = values.alias && values.alias !== "" ? values.alias : null;
    try {
      if (isCreating) {
        const useCase = container.get<CreateBankAccount>("BackOfficeCreateBankAccount");
        await useCase.run({
          bankId,
          holderName: values.holderName,
          iban: values.iban,
          bic,
          alias,
          currency: values.currency,
        });
        setProblem(null);
        toastNotifier.success("Account created", { description: values.holderName });
        router.push(safeHref(bankAccountRoutes.list(bankId)));
        router.refresh();
        return;
      }

      if (!initial) {
        throw new Error("BankAccountForm in edit mode requires `initial`.");
      }

      const useCase = container.get<UpdateBankAccount>("BackOfficeUpdateBankAccount");
      await useCase.run(initial.id, {
        holderName: values.holderName,
        iban: values.iban,
        bic,
        alias,
        currency: values.currency,
      });
      setProblem(null);
      toastNotifier.success("Changes saved", { description: values.holderName });
      router.push(safeHref(bankAccountRoutes.list(bankId)));
      router.refresh();
    } catch (err) {
      if (!(err instanceof HttpError)) throw err;
      handleHttpError(err);
    }
  });

  const cancelHref = safeHref(bankAccountRoutes.list(bankId));
  const idleSubmitLabel = isCreating ? "Create account" : "Save changes";

  // Typed recovery: a stale 404 on an edit heals by re-running the page load via
  // `onStaleAccount`. Create mode never offers a recovery action.
  const recoveryAction =
    !isCreating && problem?.type === BankAccountProblemType.NOT_FOUND && onStaleAccount ? (
      <Button
        type="button"
        variant="outline"
        size="sm"
        onClick={onStaleAccount}
        aria-label="Refresh"
        title="Refresh this account"
        data-testid="bank-account-form__mutation-error-refresh"
      >
        Refresh
      </Button>
    ) : undefined;

  return (
    <form
      ref={formRef}
      onSubmit={onSubmit}
      className="bank-account-form border-border bg-card space-y-4 rounded-lg border p-4"
      data-testid="bank-account-form"
      noValidate
    >
      {problem ? (
        <MutationError
          problem={problem}
          onDismiss={() => setProblem(null)}
          action={recoveryAction}
          testId="bank-account-form__mutation-error"
        />
      ) : null}

      <FormField
        name="holderName"
        label="Holder name"
        required
        error={errors.holderName?.message}
        helper="The legal name of the account holder."
      >
        <SingleLineTextarea
          {...register("holderName")}
          autoComplete="off"
          autoFocus={isCreating}
          data-testid="bank-account-form__holder-name"
        />
      </FormField>

      <FormField
        name="iban"
        label="IBAN"
        required
        error={errors.iban?.message}
        helper="Saved canonicalised — spaces removed and upper-cased. Must be unique."
      >
        <Input
          {...register("iban")}
          autoComplete="off"
          style={{ textTransform: "uppercase" }}
          data-testid="bank-account-form__iban"
        />
      </FormField>

      <FormField
        name="bic"
        label="BIC"
        error={errors.bic?.message}
        helper="Optional. Must match the IBAN's country; saved in upper-case."
      >
        <Input
          {...register("bic")}
          autoComplete="off"
          style={{ textTransform: "uppercase" }}
          data-testid="bank-account-form__bic"
        />
      </FormField>

      <FormField name="alias" label="Alias" error={errors.alias?.message} helper="Optional.">
        <Input {...register("alias")} autoComplete="off" data-testid="bank-account-form__alias" />
      </FormField>

      <FormField name="currency" label="Currency" required error={errors.currency?.message}>
        <select
          className={SELECT_CLASS}
          {...register("currency")}
          data-testid="bank-account-form__currency"
        >
          {BANK_ACCOUNT_CURRENCIES.map((currency) => (
            <option key={currency} value={currency}>
              {CURRENCY_LABEL[currency]}
            </option>
          ))}
        </select>
      </FormField>

      <footer className="bank-account-form__footer flex flex-col-reverse items-stretch gap-2 pt-2 sm:flex-row sm:items-center sm:justify-end">
        <Link
          href={cancelHref}
          aria-disabled={submitting || undefined}
          aria-label="Cancel and go back to accounts"
          title="Cancel and go back to accounts"
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
          aria-label={isCreating ? "Create account" : "Save account changes"}
          title={isCreating ? "Create account" : "Save account changes"}
          className="w-full sm:w-auto"
          data-testid="bank-account-form__submit"
        >
          {submitting ? (
            <>
              <Spinner className="size-3.5" testId="bank-account-form__submit-spinner" />
              Saving…
            </>
          ) : (
            idleSubmitLabel
          )}
        </Button>
      </footer>
    </form>
  );
}
