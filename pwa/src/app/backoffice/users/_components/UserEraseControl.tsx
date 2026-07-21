"use client";

import { useState } from "react";
import { useRouter } from "next/navigation";
import { TriangleAlert } from "lucide-react";
import { MutationError } from "@/components/erpify";
import { Button } from "@/components/ui/button";
import {
  Dialog,
  DialogClose,
  DialogContent,
  DialogDescription,
  DialogFooter,
  DialogHeader,
  DialogTitle,
  DialogTrigger,
} from "@/components/ui/dialog";
import { container } from "@/context/shared/dependency-injection/infrastructure/Container";
import { FulfilIdentityErasure } from "@/context/backoffice/user/application/FulfilIdentityErasure";
import type { User } from "@/context/backoffice/user/domain/User";
import { Permission } from "@/context/shared/access/domain/Permission";
import { Can } from "@/context/shared/access/infrastructure/ui";
import { HttpError } from "@/context/shared/http-client/domain/HttpError";
import { toastNotifier } from "@/context/shared/notification/infrastructure/Toast";
import { safeHref } from "@/context/shared/navigation/domain/safeHref";
import type { ProblemDetails } from "@/context/shared/error/domain/ProblemDetails";
import { userRoutes } from "../_lib/userRoutes";

const INPUT_CLASS =
  "border-border bg-background text-foreground focus-visible:ring-ring h-9 w-full rounded-md border px-2 text-sm focus-visible:ring-2 focus-visible:outline-none";

interface UserEraseControlProps {
  user: User;
}

/**
 * GDPR erasure for an identity, gated by `users.erase`. Deliberately set apart from the lifecycle
 * {@link UserStatusControl}: a destructive visual language and a type-to-confirm gate (the confirm button stays
 * disabled until the operator retypes the user's email) mark it as irreversible and unmistakable — it can never
 * be confused with "deactivate". On success it redirects to the list (the detail is now a 404); on a mapped
 * `HttpError` the dialog closes and the problem surfaces in the persistent {@link MutationError}, never inline.
 */
export function UserEraseControl({ user }: Readonly<UserEraseControlProps>) {
  const router = useRouter();
  const [open, setOpen] = useState(false);
  const [confirmText, setConfirmText] = useState("");
  const [problem, setProblem] = useState<ProblemDetails | null>(null);
  const [erasing, setErasing] = useState(false);

  const confirmed = confirmText === user.email;

  function onOpenChange(next: boolean): void {
    setOpen(next);
    if (!next) setConfirmText("");
  }

  const onConfirm = async (): Promise<void> => {
    if (erasing || !confirmed) return;
    setErasing(true);
    try {
      await container.get<FulfilIdentityErasure>("BackOfficeFulfilIdentityErasure").run(user.id);
      setOpen(false);
      setProblem(null);
      toastNotifier.success("User erased", { description: user.email });
      router.push(safeHref(userRoutes.list));
    } catch (err) {
      if (!(err instanceof HttpError)) throw err;
      setOpen(false);
      setConfirmText("");
      setProblem(err.problem);
      toastNotifier.error("Couldn't erase user — see error details");
    } finally {
      setErasing(false);
    }
  };

  return (
    <Can permission={Permission.USERS_ERASE}>
      <section
        className="user-erase border-destructive/40 space-y-3 rounded-md border p-4"
        data-testid="user-erase"
      >
        <div className="space-y-1">
          <h2 className="text-foreground text-base font-semibold">Erase (GDPR)</h2>
          <p className="text-muted-foreground text-sm">
            Permanently erase this identity to honour a right-to-erasure request. This hard-deletes
            the account and de-identifies every audit record they authored. It cannot be undone.
          </p>
        </div>

        {problem ? (
          <MutationError
            problem={problem}
            onDismiss={() => setProblem(null)}
            testId="user-erase__error"
          />
        ) : null}

        <Dialog open={open} onOpenChange={onOpenChange}>
          <DialogTrigger
            render={
              <Button
                variant="destructive"
                size="sm"
                data-icon="inline-start"
                data-testid="user-erase__trigger"
                aria-label="Erase user"
                title={`Erase ${user.email}`}
              >
                <TriangleAlert className="size-3.5" aria-hidden="true" />
                Erase (GDPR)
              </Button>
            }
          />
          <DialogContent className="sm:max-w-md" data-testid="user-erase__dialog">
            <DialogHeader>
              <div className="flex items-start gap-3">
                <span
                  className="bg-destructive/10 text-destructive flex size-10 shrink-0 items-center justify-center rounded-full"
                  aria-hidden="true"
                >
                  <TriangleAlert className="size-5" />
                </span>
                <div className="flex flex-1 flex-col gap-2">
                  <DialogTitle className="text-lg">Erase this identity</DialogTitle>
                  <DialogDescription className="text-base leading-relaxed">
                    This permanently erases{" "}
                    <span className="text-foreground font-semibold break-words">{user.email}</span>{" "}
                    and de-identifies every audit record they authored. It cannot be undone.
                  </DialogDescription>
                </div>
              </div>
            </DialogHeader>

            <div className="space-y-2">
              <label htmlFor="user-erase__confirm-input" className="text-muted-foreground text-sm">
                Type <span className="text-foreground font-medium break-words">{user.email}</span>{" "}
                to confirm.
              </label>
              <input
                id="user-erase__confirm-input"
                type="text"
                autoComplete="off"
                className={INPUT_CLASS}
                value={confirmText}
                onChange={(event) => setConfirmText(event.target.value)}
                disabled={erasing}
                data-testid="user-erase__confirm-input"
              />
            </div>

            <DialogFooter>
              <DialogClose
                render={
                  <Button
                    variant="ghost"
                    disabled={erasing}
                    aria-label="Cancel erasure"
                    title="Cancel erasure"
                    data-testid="user-erase__cancel"
                  >
                    Cancel
                  </Button>
                }
              />
              <Button
                variant="destructive"
                onClick={() => void onConfirm()}
                disabled={erasing || !confirmed}
                aria-label="Confirm erasure"
                title={`Confirm erasure of ${user.email}`}
                data-testid="user-erase__confirm"
              >
                {erasing ? "Erasing…" : "Erase permanently"}
              </Button>
            </DialogFooter>
          </DialogContent>
        </Dialog>
      </section>
    </Can>
  );
}
