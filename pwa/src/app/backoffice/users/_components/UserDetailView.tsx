"use client";

import { type ReactNode } from "react";
import Link from "next/link";
import { useParams } from "next/navigation";
import { ChevronLeft, Clock, RefreshCw } from "lucide-react";
import type { User } from "@/context/backoffice/user/domain/User";
import type { UserInput } from "@/context/backoffice/user/domain/UserRepository";
import { useResourceItem } from "@/context/shared/resource/application/useResourceItem";
import { CopyButton, EmptyState, ProblemDisplay } from "@/components/erpify";
import { HttpStatus } from "@/context/shared/http-client/domain/HttpStatus";
import { buttonVariants } from "@/components/ui/button-variants";
import { cn } from "@/components/cn";
import { dateTimeProvider } from "@/context/shared/date-time-provider/infrastructure";
import { ViewStatus } from "@/context/shared/view-state/domain/ViewState";
import { userRoutes } from "../_lib/userRoutes";
import { UserStatusBadge } from "./UserStatusBadge";
import { RolesBadges } from "./RolesBadges";
import { UserStatusControl } from "./UserStatusControl";
import { UserRolesControl } from "./UserRolesControl";
import { UserEraseControl } from "./UserEraseControl";

/**
 * Owns the single-user read path. Mounted only by the `users.read` gate, so its
 * hooks — and the request they issue — never run for a session without the
 * permission, which keeps false `ACCESS_DENIED` rows out of the audit trail.
 * The gate suppresses the request; it does not authorize. `users.read` is
 * enforced by the API on the endpoint, and that remains the only control.
 */
export function UserDetailView() {
  const params = useParams<{ id: string }>();
  const id = params?.id ?? "";
  const {
    item: user,
    state,
    problem,
    reload,
  } = useResourceItem<User, UserInput>("BackOfficeUserRepository", id);

  // Only a 404 means the identity is gone. Any other failure — a transport blip after a successful
  // mutation, a 500, a revoked permission — must not be reported as a deletion the operator did not cause.
  const isMissing = state === ViewStatus.ERROR && problem?.status === HttpStatus.NOT_FOUND;
  const hasFailed = state === ViewStatus.ERROR && !isMissing;

  const retryAction = (
    <button
      type="button"
      onClick={() => void reload()}
      className={cn(buttonVariants())}
      title="Retry loading this user"
      aria-label="Retry"
      data-testid="users-detail__retry"
    >
      <RefreshCw className="size-4" aria-hidden="true" />
      Retry
    </button>
  );

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

      {isMissing ? (
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

      {hasFailed ? (
        <div data-testid="users-detail__error">
          {problem ? (
            <ProblemDisplay problem={problem} action={retryAction} />
          ) : (
            <EmptyState
              variant="first-run"
              heading="We couldn't load this user"
              description="The request did not complete. The user has not been changed."
              action={retryAction}
            />
          )}
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
          </header>

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

          <UserRolesControl user={user} onChanged={() => void reload()} />

          <UserStatusControl user={user} onChanged={() => void reload()} />

          <UserEraseControl user={user} />
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
