"use client";

import Link from "next/link";
import { useParams } from "next/navigation";
import { ChevronLeft } from "lucide-react";
import type { User } from "@/context/backoffice/user/domain/User";
import type { UserInput } from "@/context/backoffice/user/domain/UserRepository";
import { useResourceItem } from "@/context/shared/resource/application/useResourceItem";
import { EmptyState } from "@/components/erpify";
import { buttonVariants } from "@/components/ui/button-variants";
import { cn } from "@/lib/utils";
import { safeHref } from "@/lib/safeHref";
import { PersistenceAction, ViewStatus } from "@/context/shared/view-state/domain/ViewState";
import { Can } from "@/context/shared/access/infrastructure/ui";
import { Permission } from "@/context/shared/access/domain/Permission";
import { userRoutes } from "../../_lib/userRoutes";
import { UserForm } from "../../_components/UserForm";

export default function EditUserPage() {
  const params = useParams<{ id: string }>();
  const id = params?.id ?? "";
  const {
    item: user,
    state,
    reload,
  } = useResourceItem<User, UserInput>("BackOfficeUserRepository", id);

  return (
    <div
      className="users-edit mx-auto w-full max-w-screen-md space-y-4 outline-none sm:space-y-6"
      data-testid="users-edit"
      data-state={state}
    >
      <Link
        href={safeHref(id ? userRoutes.detail(id) : userRoutes.list)}
        className="text-muted-foreground hover:text-foreground inline-flex items-center gap-1 text-xs"
        aria-label="Back to user detail"
        title="Back to user detail"
        data-testid="users-edit__back-link"
      >
        <ChevronLeft className="size-3" aria-hidden="true" />
        Back to user
      </Link>

      {state === ViewStatus.LOADING ? (
        <p
          className="text-muted-foreground text-sm"
          role="status"
          aria-live="polite"
          data-testid="users-edit__loading"
        >
          Loading user…
        </p>
      ) : null}

      {state === ViewStatus.ERROR ? (
        <div data-testid="users-edit__not-found">
          <EmptyState
            variant="first-run"
            heading="User not found"
            description="We could not find a user with that id. It may have been deleted."
            action={
              <Link
                href={userRoutes.list}
                className={cn(buttonVariants())}
                data-testid="users-edit__back-to-list"
              >
                Back to users
              </Link>
            }
          />
        </div>
      ) : null}

      {state === ViewStatus.READY && user ? (
        <Can
          permission={Permission.USERS_WRITE}
          fallback={
            <EmptyState
              variant="first-run"
              heading="Access denied"
              description="You don't have permission to edit users."
            />
          }
        >
          <header className="users-edit__header space-y-1" data-testid="users-edit__header">
            <h1
              className="text-foreground text-xl font-semibold tracking-tight sm:text-2xl"
              data-testid="users-edit__title"
            >
              Edit user
            </h1>
            <p
              className="text-muted-foreground text-sm break-words"
              data-testid="users-edit__subtitle"
            >
              Update {user.email}.
            </p>
          </header>

          <UserForm
            key={user.id}
            mode={PersistenceAction.UPDATING}
            initial={{
              id: user.id,
              email: user.email,
              roles: user.roles,
              status: user.status,
              permissions: user.permissions,
            }}
            onStaleUser={reload}
          />
        </Can>
      ) : null}
    </div>
  );
}
