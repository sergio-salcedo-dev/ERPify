"use client";

import { CopyButton } from "@/components/erpify";
import { cn } from "@/components/cn";

type UserRowActionsSurface = "table" | "cards" | "stacked";
type UserRowActionsReveal = "row" | "card" | "none";

/**
 * Static class map per reveal scope — Tailwind cannot build variants from
 * template literals. Copy stays hidden at rest and reveals on hover or
 * keyboard focus (`:focus-visible`) within the enclosing row/card; coarse
 * pointers always see it.
 */
const REVEAL_CLASS: Record<UserRowActionsReveal, string> = {
  row: "flex items-center gap-0.5 opacity-0 transition-opacity group-hover/row:opacity-100 group-focus-visible/row:opacity-100 group-has-[:focus-visible]/row:opacity-100 motion-reduce:transition-none [@media(hover:none)]:opacity-100",
  card: "flex items-center gap-0.5 opacity-0 transition-opacity group-hover/card:opacity-100 group-focus-visible/card:opacity-100 group-has-[:focus-visible]/card:opacity-100 motion-reduce:transition-none [@media(hover:none)]:opacity-100",
  none: "flex items-center gap-0.5",
};

interface UserRowActionsProps {
  id: string;
  email: string;
  /** Drives the testid prefix (`users-table__…` / `users-cards__…`). */
  surface: UserRowActionsSurface;
  /** Hover/focus reveal scope for the copy control. */
  reveal?: UserRowActionsReveal;
  className?: string;
}

/**
 * Per-row action cluster shared by the users table and cards. Copying the id is the only per-row action an
 * identity affords: an identity is not a CRUD record — it cannot be edited (the email anchors it) and it is
 * never deleted from a list. Its real capabilities (invite, change status, GDPR erase) are deliberate,
 * consequential operations that live on the detail surface, each behind its own permission and confirmation.
 */
export function UserRowActions({
  id,
  email,
  surface,
  reveal = "none",
  className,
}: Readonly<UserRowActionsProps>) {
  const prefix = `users-${surface}`;

  return (
    <div className={cn("users-row-actions flex items-center gap-0.5", className)}>
      <span className={cn("users-row-actions__reveal", REVEAL_CLASS[reveal])}>
        <CopyButton
          value={id}
          iconOnly
          size="icon-sm"
          label="Copy ID"
          copiedLabel="ID copied"
          errorLabel="Copy failed"
          title={`Copy user ${email} ID`}
          testId={`${prefix}__copy-${id}`}
        />
      </span>
    </div>
  );
}
