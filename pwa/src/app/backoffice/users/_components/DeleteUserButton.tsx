"use client";

import { type ReactElement } from "react";
import { useRouter } from "next/navigation";
import { container } from "@/context/shared/infrastructure/DependencyInjection/Container";
import type { ProblemDetails } from "@/context/shared/domain/ProblemDetails";
import type { UserRepository } from "@/context/backoffice/user/domain/UserRepository";
import { DeleteResourceButton } from "@/components/erpify";
import { userRoutes } from "../_lib/userRoutes";

interface DeleteUserButtonProps {
  id: string;
  email: string;
  /** When provided, runs after a successful delete instead of redirecting to the list. */
  onDeleted?: (id: string) => void;
  /**
   * Runs when the delete fails. The dialog closes itself and the owner surfaces
   * the problem in a persistent `<MutationError>` anchored to the mutation's
   * origin — errors never render inside the dialog.
   */
  onError: (problem: ProblemDetails) => void;
  /** Custom trigger; defaults to a destructive button with a Trash icon. */
  trigger?: ReactElement;
  triggerTestId?: string;
  /** Controlled open state (used by the per-row actions menu). */
  open?: boolean;
  onOpenChange?: (open: boolean) => void;
}

export function DeleteUserButton({
  id,
  email,
  onDeleted,
  onError,
  trigger,
  triggerTestId = "users-detail__delete-button",
  open,
  onOpenChange,
}: Readonly<DeleteUserButtonProps>) {
  const router = useRouter();

  return (
    <DeleteResourceButton
      id={id}
      resourceLabel={email}
      entityNoun="user"
      testIdPrefix="users-detail"
      onConfirmDelete={(userId) =>
        container.get<UserRepository>("BackOfficeUserRepository").delete(userId)
      }
      onDeleted={onDeleted}
      onSuccess={() => {
        router.push(userRoutes.list);
        router.refresh();
      }}
      onError={onError}
      trigger={trigger}
      triggerTestId={triggerTestId}
      open={open}
      onOpenChange={onOpenChange}
    />
  );
}
