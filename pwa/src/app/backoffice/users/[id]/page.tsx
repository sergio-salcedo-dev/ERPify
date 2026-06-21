"use client";

import { useState, type ReactNode } from "react";
import Link from "next/link";
import { useParams } from "next/navigation";
import { ChevronLeft, Clock, Pencil, RefreshCw } from "lucide-react";
import type { User } from "@/context/backoffice/user/domain/User";
import type { UserInput } from "@/context/backoffice/user/domain/UserRepository";
import type { ProblemDetails } from "@/context/shared/error/domain/ProblemDetails";
import { useResourceItem } from "@/context/shared/resource/application/useResourceItem";
import { CopyButton, EmptyState, MutationError } from "@/components/erpify";
import { buttonVariants } from "@/components/ui/button-variants";
import { cn } from "@/context/shared/styling/infrastructure/classNames";
import { safeHref } from "@/context/shared/navigation/domain/safeHref";
import { dateTimeProvider } from "@/context/shared/date-time-provider/infrastructure";
import { ViewStatus } from "@/context/shared/view-state/domain/ViewState";
import { Can } from "@/context/shared/access/infrastructure/ui";
import { Permission } from "@/context/shared/access/domain/Permission";
import { userRoutes } from "../_lib/userRoutes";
import { DeleteUserButton } from "../_components/DeleteUserButton";
import { UserStatusBadge } from "../_components/UserStatusBadge";
import { RolesBadges } from "../_components/RolesBadges";

export default function UserDetailPage() {
  const params = useParams<{ id: string }>();
  const id = params?.id ?? "";
  const { item: user, state } = useResourceItem<User, UserInput>("BackOfficeUserRepository", id);
  const [deleteProblem, setDeleteProblem] = useState<ProblemDetails | null>(null);

  return (
    <div
      className="users-detail mx-auto w-full max-w-screen-2xl space-y-4 outline-none sm:space-y-6"
      data-testid="users-detail"
      data-state={state}
    >
      <Link
        href={userRoutes.list}
        className="text-muted-foreground hover:text-foreground inline-flex items-center gap-1 text-xs"
        aria-label="Back to users"
        title="Back to users"
        data-testid="users-detail__back-link"
      >
        <ChevronLeft className="size-3" aria-hidden="true" />
        Back to users
      </Link>

      {state === ViewStatus.LOADING ? (
        <p
          className="text-muted-foreground text-sm"
          role="status"
          aria-live="polite"
          data-testid="users-detail__loading"
        >
          Loading user…
        </p>
      ) : null}

      {state === ViewStatus.ERROR ? (
        <div data-testid="users-detail__not-found">
          <EmptyState
            variant="first-run"
            heading="User not found"
            description="We could not find a user with that id. It may have been deleted."
            action={
              <Link
                href={userRoutes.list}
                className={cn(buttonVariants())}
                data-testid="users-detail__back-to-list"
              >
                Back to users
              </Link>
            }
          />
        </div>
      ) : null}

      {state === ViewStatus.READY && user ? (
        <>
          <header
            className="users-detail__header flex flex-col gap-3 sm:flex-row sm:items-start sm:justify-between"
            data-testid="users-detail__header"
          >
            <div className="min-w-0">
              <h1
                className="text-foreground min-w-0 text-xl font-semibold tracking-tight break-words sm:text-2xl"
                data-testid="users-detail__email"
              >
                {user.email}
              </h1>
              <div className="mt-1 flex min-w-0 items-center gap-2">
                <UserStatusBadge status={user.status} testId="users-detail__status-badge" />
              </div>
            </div>
            <div className="flex flex-wrap items-center gap-2 sm:flex-nowrap">
              <Can permission={Permission.USERS_WRITE}>
                <Link
                  href={safeHref(userRoutes.edit(user.id))}
                  className={cn(buttonVariants({ variant: "outline", size: "sm" }))}
                  data-icon="inline-start"
                  data-testid="users-detail__edit-button"
                  aria-label={`Edit user ${user.email}`}
                  title={`Edit user ${user.email}`}
                >
                  <Pencil className="size-3.5" aria-hidden="true" />
                  Edit
                </Link>
              </Can>
              <Can permission={Permission.USERS_DELETE}>
                <DeleteUserButton id={user.id} email={user.email} onError={setDeleteProblem} />
              </Can>
            </div>
          </header>

          {deleteProblem ? (
            <MutationError
              problem={deleteProblem}
              onDismiss={() => setDeleteProblem(null)}
              testId="users-detail__delete-error"
            />
          ) : null}

          <dl
            className="users-detail__meta border-border bg-card grid grid-cols-1 gap-4 rounded-lg border p-4 sm:grid-cols-2"
            data-testid="users-detail__meta"
          >
            <Field label="Email" value={user.email} testId="users-detail__field-email" />
            <div className="users-detail__field">
              <dt className="text-muted-foreground text-xs font-medium tracking-wide uppercase">
                Status
              </dt>
              <dd className="mt-1">
                <UserStatusBadge status={user.status} testId="users-detail__field-status" />
              </dd>
            </div>
            <div className="users-detail__field sm:col-span-2">
              <dt className="text-muted-foreground text-xs font-medium tracking-wide uppercase">
                Roles
              </dt>
              <dd className="mt-1">
                <RolesBadges roles={user.roles} testId="users-detail__field-roles" />
              </dd>
            </div>
            <div className="users-detail__field sm:col-span-2">
              <dt className="text-muted-foreground text-xs font-medium tracking-wide uppercase">
                Permissions
              </dt>
              <dd
                className="text-foreground mt-1 flex flex-wrap gap-1.5 text-xs"
                data-testid="users-detail__field-permissions"
              >
                {user.permissions.length > 0
                  ? user.permissions.map((permission) => (
                      <span
                        key={permission}
                        className="border-border bg-muted rounded border px-1.5 py-0.5 font-mono"
                      >
                        {permission}
                      </span>
                    ))
                  : "—"}
              </dd>
            </div>
            <Field
              label="Created"
              value={dateTimeProvider.formatIsoToRelative(user.createdAt)}
              valueTitle={dateTimeProvider.formatIsoToLocalDateTime(user.createdAt)}
              icon={<Clock className="size-3.5" aria-hidden="true" />}
              testId="users-detail__field-created"
            />
            <Field
              label="Updated"
              value={dateTimeProvider.formatIsoToRelative(user.updatedAt)}
              valueTitle={dateTimeProvider.formatIsoToLocalDateTime(user.updatedAt)}
              icon={<RefreshCw className="size-3.5" aria-hidden="true" />}
              testId="users-detail__field-updated"
            />
            <div className="users-detail__field sm:col-span-2">
              <dt className="text-muted-foreground text-xs font-medium tracking-wide uppercase">
                Identifier
              </dt>
              <dd className="mt-1 flex items-center gap-2">
                <span
                  className="users-detail__id text-foreground min-w-0 truncate font-mono text-xs"
                  data-testid="users-detail__id"
                >
                  {user.id}
                </span>
                <CopyButton
                  value={user.id}
                  iconOnly
                  size="icon-sm"
                  label="Copy ID"
                  copiedLabel="ID copied"
                  errorLabel="Copy failed"
                  title={`Copy user ID ${user.id}`}
                  testId="users-detail__id-copy"
                />
              </dd>
            </div>
          </dl>
        </>
      ) : null}
    </div>
  );
}

function Field({
  label,
  value,
  valueClassName,
  valueTitle,
  icon,
  testId,
}: Readonly<{
  label: string;
  value: string;
  valueClassName?: string;
  valueTitle?: string;
  icon?: ReactNode;
  testId?: string;
}>) {
  return (
    <div className="users-detail__field">
      <dt className="text-muted-foreground flex items-center gap-1.5 text-xs font-medium tracking-wide uppercase">
        {icon}
        {label}
      </dt>
      <dd
        className={cn("text-foreground mt-1 text-sm", valueClassName)}
        title={valueTitle}
        data-testid={testId}
      >
        {value}
      </dd>
    </div>
  );
}
