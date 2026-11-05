"use client";

import { useState } from "react";
import { MutationError } from "@/components/erpify";
import { container } from "@/context/shared/dependency-injection/infrastructure/Container";
import { ChangeUserStatus } from "@/context/backoffice/user/application/ChangeUserStatus";
import type { User } from "@/context/backoffice/user/domain/User";
import { UserStatus } from "@/context/shared/access/domain/UserStatus";
import { Permission } from "@/context/shared/access/domain/Permission";
import { Can } from "@/context/shared/access/infrastructure/ui";
import { useSession } from "@/context/shared/access/application/useSession";
import { HttpError } from "@/context/shared/http-client/domain/HttpError";
import { toastNotifier } from "@/context/shared/notification/infrastructure/Toast";
import type { ProblemDetails } from "@/context/shared/error/domain/ProblemDetails";
import { isOwnIdentity } from "../_lib/isOwnIdentity";
import { CurrentPasswordDialog, isInvalidCurrentPassword } from "./CurrentPasswordDialog";

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
 * control. A failure (403 / 409 last-admin / 409 illegal transition / 422 / 429 spent proof budget) surfaces in
 * the persistent {@link MutationError}, mirroring the bank-account status control.
 *
 * The button does not send anything: it opens {@link CurrentPasswordDialog}, because retiring another identity
 * is not something a borrowed session may do alone. The operator's own password travels with the write; a wrong
 * one stays on the dialog's field and keeps it open.
 *
 * On the operator's own identity it offers no control either: the API refuses a self-targeted transition, so
 * the section says another administrator makes it.
 */
export function UserStatusControl({ user, onChanged }: Readonly<UserStatusControlProps>) {
  const [selected, setSelected] = useState<TransitionTarget>(UserStatus.SUSPENDED);
  const [problem, setProblem] = useState<ProblemDetails | null>(null);
  const [confirming, setConfirming] = useState(false);
  const { session } = useSession();

  if (user.status !== UserStatus.ACTIVE) {
    return null;
  }

  if (isOwnIdentity(session, user.id)) {
    return (
      <Can permission={Permission.USERS_CHANGE_STATUS}>
        <section className="user-status border-border space-y-1 rounded-md border p-4">
          <h2 className="text-foreground text-base font-semibold">Status</h2>
          <p className="text-muted-foreground text-sm" data-testid="user-status__self">
            You cannot suspend or deactivate your own account. Another administrator has to make
            this change.
          </p>
        </section>
      </Can>
    );
  }

  // A wrong password is the dialog's to show on its field, so it is handed back; every other failure
  // is surfaced here and the dialog closes behind it.
  const confirm = async (currentPassword: string): Promise<void> => {
    try {
      const useCase = container.get<ChangeUserStatus>("BackOfficeChangeUserStatus");
      const updated = await useCase.run(user.id, selected, currentPassword);
      setProblem(null);
      toastNotifier.success("Status updated", { description: updated.status });
      onChanged(updated);
    } catch (err) {
      if (!(err instanceof HttpError)) throw err;
      if (isInvalidCurrentPassword(err)) throw err;
      setProblem(err.problem);
    }
  };

  const targetLabel = TRANSITIONS.find((transition) => transition.value === selected)?.label;

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
            onClick={() => setConfirming(true)}
            title={`Change ${user.email} status`}
            aria-label="Change status"
            data-testid="user-status__save"
          >
            Change status
          </button>
        </div>
      </section>
      <CurrentPasswordDialog
        open={confirming}
        onOpenChange={setConfirming}
        title={`${targetLabel} this member`}
        description={`Enter your password to ${targetLabel?.toLowerCase()} ${user.email}. This cannot be undone from here.`}
        confirmLabel="Change status"
        testIdPrefix="user-status"
        onConfirm={confirm}
      />
    </Can>
  );
}
