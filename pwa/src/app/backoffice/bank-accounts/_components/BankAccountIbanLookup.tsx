"use client";

import { useCallback, useRef, useState } from "react";
import { useRouter } from "next/navigation";
import { Search } from "lucide-react";
import { container } from "@/context/shared/dependency-injection/infrastructure/Container";
import { LookupBankAccountByIban } from "@/context/backoffice/bankaccount/application/LookupBankAccountByIban";
import { HttpError } from "@/context/shared/http-client/domain/HttpError";
import { HttpStatus } from "@/context/shared/http-client/domain/HttpStatus";
import type { ProblemDetails } from "@/context/shared/error/domain/ProblemDetails";
import { useZodForm } from "@/context/shared/validation/infrastructure";
import {
  BankAccountIbanLookupSchema,
  type BankAccountIbanLookupFormValues,
} from "@/context/backoffice/bankaccount/application/schemas/BankAccountIbanLookupSchema";
import { safeHref } from "@/context/shared/navigation/domain/safeHref";
import { FormField, MutationError } from "@/components/erpify";
import { Button } from "@/components/ui/button";
import { Input } from "@/components/ui/input";
import { bankAccountRoutes } from "../_lib/bankAccountRoutes";

/**
 * Exact-match "find account by IBAN" search box — the non-logging counterpart to the
 * regular `holderName`/`alias`/`bank` filters, which never carry `iban` (the value is
 * financial PII and must never reach a query string or the filters panel's own state,
 * which is mirrored into that string on every keystroke). Submits once, over the
 * dedicated POST lookup, rather than debouncing into the list's filter machinery.
 *
 * A well-formed IBAN with no match (404) is a normal search outcome, not a mutation
 * failure — it renders as a lightweight inline result (`<output>`, the live region for
 * "the result of a user action"), distinct from `<MutationError>`, which is reserved
 * for a genuine failure (validation the client missed, network, 5xx).
 */
export function BankAccountIbanLookup() {
  const router = useRouter();
  const [problem, setProblem] = useState<ProblemDetails | null>(null);
  const [notFound, setNotFound] = useState(false);

  // Guards against a stale response landing after a newer one: RHF's `isSubmitting` disables the
  // affordances but does not stop a second submit already in flight from resolving out of order
  // (e.g. Enter on IBAN A, edit to IBAN B, Enter again before A's response arrives). Only the
  // outcome of the LATEST request is ever applied.
  const latestRequestId = useRef(0);

  const {
    register,
    handleSubmit,
    formState: { errors, isSubmitting },
  } = useZodForm<BankAccountIbanLookupFormValues>(BankAccountIbanLookupSchema, {
    defaultValues: { iban: "" },
  });

  const submitLookup = useCallback(
    async (values: BankAccountIbanLookupFormValues): Promise<void> => {
      const requestId = ++latestRequestId.current;
      setProblem(null);
      setNotFound(false);
      try {
        const useCase = container.get<LookupBankAccountByIban>("BackOfficeLookupBankAccountByIban");
        const account = await useCase.run(values.iban);
        if (requestId !== latestRequestId.current) return;
        router.push(safeHref(bankAccountRoutes.detail(account.id)));
      } catch (err) {
        if (requestId !== latestRequestId.current) return;
        if (!(err instanceof HttpError)) throw err;
        if (err.problem.status === HttpStatus.NOT_FOUND) {
          setNotFound(true);
          return;
        }
        setProblem(err.problem);
      }
    },
    [router],
  );

  return (
    <form
      onSubmit={(event) => {
        // Bound to the event, never called during render: `submitLookup` reads/writes the
        // request-id ref, and react-hooks/refs forbids passing a ref-touching callback to
        // handleSubmit() while it is being COMPUTED at render time — only invoking it here.
        void handleSubmit(submitLookup)(event);
      }}
      className="bank-accounts-iban-lookup flex flex-col gap-2"
      data-testid="bank-accounts-iban-lookup"
      noValidate
    >
      <div className="flex items-start gap-2">
        <FormField
          name="iban"
          label="Find by IBAN"
          error={errors.iban?.message}
          className="min-w-56"
        >
          <Input
            {...register("iban")}
            placeholder="e.g. DE89370400440532013000"
            autoComplete="off"
            disabled={isSubmitting}
            data-testid="bank-accounts-iban-lookup__input"
          />
        </FormField>
        <Button
          type="submit"
          variant="outline"
          size="icon"
          disabled={isSubmitting}
          aria-label="Find account by IBAN"
          title="Find account by IBAN"
          className="mt-6"
          data-testid="bank-accounts-iban-lookup__submit"
        >
          <Search className="size-4" aria-hidden="true" />
          <span className="sr-only">Find account by IBAN</span>
        </Button>
      </div>

      {notFound ? (
        <output
          className="text-muted-foreground text-sm"
          data-testid="bank-accounts-iban-lookup__not-found"
        >
          No account found for that IBAN.
        </output>
      ) : null}

      {problem ? (
        <MutationError
          problem={problem}
          onDismiss={() => setProblem(null)}
          testId="bank-accounts-iban-lookup__error"
        />
      ) : null}
    </form>
  );
}
