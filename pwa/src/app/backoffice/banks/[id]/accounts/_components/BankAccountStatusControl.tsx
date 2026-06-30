"use client";

import { useState } from "react";
import { MutationError } from "@/components/erpify";
import { container } from "@/context/shared/dependency-injection/infrastructure/Container";
import { ChangeBankAccountStatus } from "@/context/backoffice/bankaccount/application/ChangeBankAccountStatus";
import { BANK_ACCOUNT_STATUSES } from "@/context/backoffice/bankaccount/application/schemas/BankAccountSchema";
import type {
  BankAccount,
  BankAccountStatus,
} from "@/context/backoffice/bankaccount/domain/BankAccount";
import { HttpError } from "@/context/shared/http-client/domain/HttpError";
import { toastNotifier } from "@/context/shared/notification/infrastructure/Toast";
import type { ProblemDetails } from "@/context/shared/error/domain/ProblemDetails";

/** Presentation labels keyed by the wire enum identity (never the raw code to the user). */
const STATUS_LABEL: Record<BankAccountStatus, string> = {
  ACTIVE: "Active",
  INACTIVE: "Inactive",
  CLOSED: "Closed",
};

const SELECT_CLASS =
  "border-border bg-background text-foreground focus-visible:ring-ring h-9 w-full rounded-md border px-2 text-sm focus-visible:ring-2 focus-visible:outline-none sm:w-56";

interface BankAccountStatusControlProps {
  account: BankAccount;
  /** Hands the freshly transitioned account back so the page reflects the new status in place. */
  onChanged: (account: BankAccount) => void;
}

/**
 * Lifecycle status transition, deliberately separate from the descriptive edit form: status is a
 * distinct intent that hits its own `PATCH /bank-accounts/{id}/status` endpoint. A failure surfaces in
 * the persistent {@link MutationError}; a redundant transition is a server-side no-op.
 */
export function BankAccountStatusControl({
  account,
  onChanged,
}: Readonly<BankAccountStatusControlProps>) {
  const [selected, setSelected] = useState<BankAccountStatus>(account.status);
  const [problem, setProblem] = useState<ProblemDetails | null>(null);
  const [saving, setSaving] = useState(false);
  const isDirty = selected !== account.status;

  // Re-seed the pending selection whenever the account prop arrives with a different status from
  // another source (a refetch, a realtime update): the dropdown and `isDirty` track the
  // authoritative status instead of silently desyncing. Adjusting state during render is the
  // React-sanctioned reset, not an effect. A save in flight defers the re-seed (the control is
  // disabled then), so a disabled dropdown never jumps mid-save; it syncs once `saving` clears.
  const [syncedStatus, setSyncedStatus] = useState<BankAccountStatus>(account.status);
  if (account.status !== syncedStatus && !saving) {
    setSyncedStatus(account.status);
    setSelected(account.status);
  }

  const onSubmit = async (): Promise<void> => {
    setSaving(true);
    try {
      const useCase = container.get<ChangeBankAccountStatus>("BackOfficeChangeBankAccountStatus");
      const updated = await useCase.run(account.id, selected);
      setProblem(null);
      toastNotifier.success("Status updated", { description: STATUS_LABEL[updated.status] });
      onChanged(updated);
    } catch (err) {
      if (!(err instanceof HttpError)) throw err;
      setProblem(err.problem);
    } finally {
      setSaving(false);
    }
  };

  return (
    <section
      className="bank-account-status border-border space-y-3 rounded-md border p-4"
      data-testid="bank-account-status"
    >
      <div className="space-y-1">
        <h2 className="text-foreground text-base font-semibold">Status</h2>
        <p className="text-muted-foreground text-sm">
          Transition the account&apos;s lifecycle. Only a CLOSED account can be deleted.
        </p>
      </div>

      {problem ? (
        <MutationError
          problem={problem}
          onDismiss={() => setProblem(null)}
          testId="bank-account-status__error"
        />
      ) : null}

      <div className="flex flex-col gap-2 sm:flex-row sm:items-center">
        <label htmlFor="bank-account-status__select" className="sr-only">
          Account status
        </label>
        <select
          id="bank-account-status__select"
          className={SELECT_CLASS}
          value={selected}
          onChange={(event) => setSelected(event.target.value as BankAccountStatus)}
          disabled={saving}
          data-testid="bank-account-status__select"
        >
          {BANK_ACCOUNT_STATUSES.map((status) => (
            <option key={status} value={status}>
              {STATUS_LABEL[status]}
            </option>
          ))}
        </select>
        <button
          type="button"
          className="bg-primary text-primary-foreground hover:bg-primary/90 inline-flex h-9 items-center justify-center rounded-md px-4 text-sm font-medium disabled:pointer-events-none disabled:opacity-50"
          disabled={!isDirty || saving}
          onClick={() => void onSubmit()}
          title={`Change ${account.holderName} status`}
          aria-label="Change status"
          data-testid="bank-account-status__save"
        >
          {saving ? "Saving…" : "Change status"}
        </button>
      </div>
    </section>
  );
}
