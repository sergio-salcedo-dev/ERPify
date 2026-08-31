"use client";

import { type ReactNode } from "react";
import { KeyRound, Mail } from "lucide-react";
import { useSession } from "@/context/shared/access/application/useSession";
import { CopyButton, StatusBadge } from "@/components/erpify";
import { cn } from "@/components/cn";
import { ChangePasswordForm } from "./ChangePasswordForm";
import { RecoverySecretPanel } from "./RecoverySecretPanel";

/**
 * The signed-in user's own account view. It renders exactly what `/me` carries —
 * email, id, roles, permissions — and nothing else: the `status` the session object
 * exposes is synthesised client-side from the 200, so showing it would assert
 * something the response never said.
 *
 * No permission gate: every identity reads and changes its own account, and the API
 * scopes both endpoints to the session's own identity.
 */
export function ProfileView() {
  const { session } = useSession();

  if (!session) return null;

  const { email, id, roles, permissions } = session.user;

  return (
    <div className="account-profile flex flex-col gap-6" data-testid="account-profile">
      <header
        className="account-profile__hero flex flex-col gap-2"
        data-testid="account-profile__hero"
      >
        <h1
          className="text-foreground text-2xl font-semibold tracking-tight"
          data-testid="account-profile__title"
        >
          My profile
        </h1>
        <p className="text-muted-foreground max-w-2xl text-sm leading-relaxed">
          Your account as the server sees it, and where you keep the two credentials that get you
          into it.
        </p>
      </header>

      <dl
        className="account-profile__meta border-border bg-card grid grid-cols-1 gap-4 rounded-lg border p-4 sm:grid-cols-2"
        data-testid="account-profile__meta"
      >
        <Field
          label="Email"
          icon={<Mail className="size-3.5" aria-hidden="true" />}
          testId="account-profile__field-email"
        >
          {email}
        </Field>

        <Field label="Roles" testId="account-profile__field-roles">
          <BadgeList
            values={roles}
            variant="info"
            testIdPrefix="account-profile__role"
            emptyLabel="No roles assigned"
          />
        </Field>

        <Field
          label="Permissions"
          className="sm:col-span-2"
          testId="account-profile__field-permissions"
        >
          <BadgeList
            values={permissions}
            variant="neutral"
            testIdPrefix="account-profile__permission"
            emptyLabel="No permissions granted"
          />
        </Field>

        <Field label="Identifier" className="sm:col-span-2" testId="account-profile__field-id">
          <span className="flex items-center gap-2">
            <span
              className="account-profile__id text-foreground min-w-0 truncate font-mono text-xs"
              data-testid="account-profile__id"
            >
              {id}
            </span>
            <CopyButton
              value={id}
              iconOnly
              size="icon-sm"
              label="Copy ID"
              copiedLabel="ID copied"
              errorLabel="Copy failed"
              title={`Copy account ID ${id}`}
              testId="account-profile__id-copy"
            />
          </span>
        </Field>
      </dl>

      <section
        className="account-profile__security flex flex-col gap-3"
        aria-labelledby="account-profile__security-title"
        data-testid="account-profile__security"
      >
        <h2
          id="account-profile__security-title"
          className="text-foreground flex items-center gap-2 text-lg font-semibold"
          data-testid="account-profile__security-title"
        >
          <KeyRound className="size-4" aria-hidden="true" />
          Security
        </h2>
        <p className="text-muted-foreground max-w-2xl text-sm leading-relaxed">
          Changing your password signs out every other device. This one stays signed in.
        </p>
        <ChangePasswordForm />
      </section>

      <RecoverySecretPanel />
    </div>
  );
}

function Field({
  label,
  children,
  icon,
  className,
  testId,
}: Readonly<{
  label: string;
  children: ReactNode;
  icon?: ReactNode;
  className?: string;
  testId?: string;
}>) {
  return (
    <div className={cn("account-profile__field", className)}>
      <dt className="text-muted-foreground flex items-center gap-1.5 text-xs font-medium tracking-wide uppercase">
        {icon}
        {label}
      </dt>
      <dd className="text-foreground mt-1 min-w-0 text-sm" data-testid={testId}>
        {children}
      </dd>
    </div>
  );
}

function BadgeList({
  values,
  variant,
  testIdPrefix,
  emptyLabel,
}: Readonly<{
  values: readonly string[];
  variant: "info" | "neutral";
  testIdPrefix: string;
  emptyLabel: string;
}>) {
  if (values.length === 0) {
    return <span className="text-muted-foreground text-sm">{emptyLabel}</span>;
  }
  return (
    <span className="flex flex-wrap gap-1.5">
      {values.map((value) => (
        <StatusBadge
          key={value}
          variant={variant}
          label={value}
          testId={`${testIdPrefix}-${value}`}
        />
      ))}
    </span>
  );
}
