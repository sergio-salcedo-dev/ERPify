"use client";

import { useState } from "react";
import { MutationError } from "@/components/erpify";
import { container } from "@/context/shared/dependency-injection/infrastructure/Container";
import { ChangeUserStatus } from "@/context/backoffice/user/application/ChangeUserStatus";
import type { User } from "@/context/backoffice/user/domain/User";
import { UserStatus } from "@/context/shared/access/domain/UserStatus";
import { Permission } from "@/context/shared/access/domain/Permission";
import { Can } from "@/context/shared/access/infrastructure/ui";
import { HttpError } from "@/context/shared/http-client/domain/HttpError";
import { toastNotifier } from "@/context/shared/notification/infrastructure/Toast";
import type { ProblemDetails } from "@/context/shared/error/domain/ProblemDetails";

/** The two legal transitions out of ACTIVE — the identity lifecycle is unidirectional, so there is no reinstate. */
type TransitionTarget = typeof UserStatus.SUSPENDED | typeof UserStatus.DEACTIVATED;

const TRANSITIONS: ReadonlyArray<{ value: TransitionTarget; label: string }> = [
  { value: UserStatus.SUSPENDED, label: "Suspend" },
  { value: UserStatus.DEACTIVATED, label: "Deactivate" },
];

const SELECT_CLASS =
  "border-border bg-background text-foreground focus-visible:ring-ring h-9 w-full rounded-md border px-2 text-sm focus-visible:ring-2 focus-visible:outline-none sm:w-56";

interface UserStatusControlProps {
  user: User;
  /** Hands the freshly transitioned identity back so the detail reflects the new status in place. */
  onChanged: (user: User) => void;
}

/**
 * Lifecycle status transition for an identity, gated by `users.changeStatus`. Offered only for an ACTIVE
 * identity — the transition is unidirectional (no reinstate), so a suspended/deactivated user shows no
 * control. A failure (403 / 409 last-admin / 409 illegal transition / 422) surfaces in the persistent
 * {@link MutationError}, mirroring the bank-account status control.
 */
export function UserStatusControl({ user, onChanged }: Readonly<UserStatusControlProps>) {
  const [selected, setSelected] = useState<TransitionTarget>(UserStatus.SUSPENDED);
  const [problem, setProblem] = useState<ProblemDetails | null>(null);
  const [saving, setSaving] = useState(false);

  if (user.status !== UserStatus.ACTIVE) {
    return null;
  }

  const onSubmit = async (): Promise<void> => {
    setSaving(true);
    try {
      const useCase = container.get<ChangeUserStatus>("BackOfficeChangeUserStatus");
      const updated = await useCase.run(user.id, selected);
      setProblem(null);
      toastNotifier.success("Status updated", { description: updated.status });
      onChanged(updated);
    } catch (err) {
      if (!(err instanceof HttpError)) throw err;
      setProblem(err.problem);
    } finally {
      setSaving(false);
    }
  };

  return (
    <Can permission={Permission.USERS_CHANGE_STATUS}>
      <section
        className="user-status border-border space-y-3 rounded-md border p-4"
        data-testid="user-status"
      >
        <div className="space-y-1">
          <h2 className="text-foreground text-base font-semibold">Status</h2>
          <p className="text-muted-foreground text-sm">
            Block this member&apos;s access while preserving their history. Suspend is reversible
            policy; deactivate retires the account. Neither can be undone from here.
          </p>
        </div>

        {problem ? (
          <MutationError
            problem={problem}
            onDismiss={() => setProblem(null)}
            testId="user-status__error"
          />
        ) : null}

        <div className="flex flex-col gap-2 sm:flex-row sm:items-center">
          <label htmlFor="user-status__select" className="sr-only">
            Status transition
          </label>
          <select
            id="user-status__select"
            className={SELECT_CLASS}
            value={selected}
            onChange={(event) => setSelected(event.target.value as TransitionTarget)}
            disabled={saving}
            data-testid="user-status__select"
          >
            {TRANSITIONS.map((transition) => (
              <option key={transition.value} value={transition.value}>
                {transition.label}
              </option>
            ))}
          </select>
          <button
            type="button"
            className="bg-primary text-primary-foreground hover:bg-primary/90 inline-flex h-9 items-center justify-center rounded-md px-4 text-sm font-medium disabled:pointer-events-none disabled:opacity-50"
            disabled={saving}
            onClick={() => void onSubmit()}
            title={`Change ${user.email} status`}
            aria-label="Change status"
            data-testid="user-status__save"
          >
            {saving ? "Saving…" : "Change status"}
          </button>
        </div>
      </section>
    </Can>
  );
}
