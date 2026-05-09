"use client";

import { useState, type FormEvent } from "react";
import Link from "next/link";
import { useRouter } from "next/navigation";
import { container } from "@/context/shared/infrastructure/DependencyInjection/Container";
import { CreateBank } from "@/context/backoffice/bank/application/CreateBank";
import { UpdateBank } from "@/context/backoffice/bank/application/UpdateBank";
import { HttpError } from "@/context/shared/infrastructure/HttpClient/HttpError";
import type { ProblemDetails, ProblemViolation } from "@/context/shared/domain/ProblemDetails";
import { FormField, ProblemDisplay } from "@/components/erpify";
import { Button, buttonVariants } from "@/components/ui/button";
import { Input } from "@/components/ui/input";
import { cn } from "@/lib/utils";

type Mode = "create" | "edit";

interface BankFormInitial {
  id: string;
  name: string;
  shortName: string;
}

interface BankFormProps {
  mode: Mode;
  initial?: BankFormInitial;
}

export function BankForm({ mode, initial }: BankFormProps) {
  const router = useRouter();
  const [name, setName] = useState(initial?.name ?? "");
  const [shortName, setShortName] = useState(initial?.shortName ?? "");
  const [violations, setViolations] = useState<ProblemViolation[]>([]);
  const [problem, setProblem] = useState<ProblemDetails | null>(null);
  const [submitting, setSubmitting] = useState(false);

  async function handleSubmit(event: FormEvent<HTMLFormElement>): Promise<void> {
    event.preventDefault();
    if (submitting) return;
    setViolations([]);
    setProblem(null);
    setSubmitting(true);

    try {
      if (mode === "create") {
        const useCase = container.get<CreateBank>("BackOfficeCreateBank");
        const created = await useCase.run({ name, shortName });
        router.push(`/backoffice/banks/${created.id}`);
        router.refresh();
        return;
      }

      if (!initial) {
        throw new Error("BankForm in edit mode requires `initial`.");
      }

      const useCase = container.get<UpdateBank>("BackOfficeUpdateBank");
      const updated = await useCase.run(initial.id, { name, shortName });
      router.push(`/backoffice/banks/${updated.id}`);
      router.refresh();
    } catch (err) {
      if (err instanceof HttpError) {
        if (err.problem.status === 422 && err.problem.violations) {
          setViolations(err.problem.violations);
        } else {
          setProblem(err.problem);
        }
        return;
      }
      throw err;
    } finally {
      setSubmitting(false);
    }
  }

  const cancelHref =
    mode === "edit" && initial ? `/backoffice/banks/${initial.id}` : "/backoffice/banks";

  return (
    <form
      onSubmit={handleSubmit}
      className="bank-form border-border bg-card space-y-4 rounded-lg border p-4"
      data-testid="bank-form"
      noValidate
    >
      {problem ? <ProblemDisplay problem={problem} variant="inline" /> : null}

      <FormField name="name" label="Name" required violations={violations}>
        <Input
          value={name}
          onChange={(event) => setName(event.currentTarget.value)}
          maxLength={255}
          autoComplete="off"
          autoFocus={mode === "create"}
          data-testid="bank-form__name"
        />
      </FormField>

      <FormField name="shortName" label="Short name" required violations={violations}>
        <Input
          value={shortName}
          onChange={(event) => setShortName(event.currentTarget.value)}
          maxLength={50}
          autoComplete="off"
          data-testid="bank-form__short-name"
        />
      </FormField>

      <footer className="bank-form__footer flex flex-col-reverse items-stretch gap-2 pt-2 sm:flex-row sm:items-center sm:justify-end">
        <Link
          href={cancelHref}
          aria-disabled={submitting || undefined}
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
          className="w-full sm:w-auto"
          data-testid="bank-form__submit"
        >
          {submitting ? "Saving…" : mode === "create" ? "Create bank" : "Save changes"}
        </Button>
      </footer>
    </form>
  );
}
