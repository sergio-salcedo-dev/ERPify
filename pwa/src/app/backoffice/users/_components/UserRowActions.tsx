"use client";

import { useState } from "react";
import Link from "next/link";
import { MoreHorizontal, Pencil, Trash2 } from "lucide-react";
import { CopyButton } from "@/components/erpify";
import { Button } from "@/components/ui/button";
import { buttonVariants } from "@/components/ui/button-variants";
import {
  DropdownMenu,
  DropdownMenuContent,
  DropdownMenuItem,
  DropdownMenuTrigger,
} from "@/components/ui/dropdown-menu";
import { cn } from "@/components/cn";
import { safeHref } from "@/context/shared/navigation/domain/safeHref";
import type { ProblemDetails } from "@/context/shared/error/domain/ProblemDetails";
import { Can } from "@/context/shared/access/infrastructure/ui";
import { Permission } from "@/context/shared/access/domain/Permission";
import { userRoutes } from "../_lib/userRoutes";
import { DeleteUserButton } from "./DeleteUserButton";

type UserRowActionsSurface = "table" | "cards" | "stacked";
type UserRowActionsReveal = "row" | "card" | "none";

/**
 * Static class map per reveal scope — Tailwind cannot build variants from
 * template literals. Copy/Edit stay hidden at rest and reveal on hover or
 * keyboard focus (`:focus-visible`) within the enclosing row/card; coarse
 * pointers always see them.
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
  /** Hover/focus reveal scope for Copy/Edit; `⋯` is always visible. */
  reveal?: UserRowActionsReveal;
  onUserDeleted?: (id: string) => void;
  /** Failed delete → the page anchors the problem in its persistent error surface. */
  onUserDeleteFailed: (id: string, problem: ProblemDetails) => void;
  className?: string;
}

/**
 * Per-row action cluster shared by the users table and cards. Copy ID is always
 * available (non-destructive); Edit is gated behind `users.write` and the
 * destructive Delete behind `users.delete`, both demoted into a `⋯` overflow
 * menu. A missing permission hides the control entirely (never disables).
 */
export function UserRowActions({
  id,
  email,
  surface,
  reveal = "none",
  onUserDeleted,
  onUserDeleteFailed,
  className,
}: Readonly<UserRowActionsProps>) {
  const [deleteOpen, setDeleteOpen] = useState(false);
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
        <Can permission={Permission.USERS_WRITE}>
          <Link
            href={safeHref(userRoutes.edit(id))}
            className={cn(buttonVariants({ variant: "ghost", size: "icon-sm" }))}
            aria-label="Edit"
            title={`Edit user ${email}`}
            data-testid={`${prefix}__edit-${id}`}
          >
            <Pencil className="size-3.5" aria-hidden="true" />
            <span className="sr-only">Edit</span>
          </Link>
        </Can>
      </span>
      <Can permission={Permission.USERS_DELETE}>
        <DropdownMenu>
          <DropdownMenuTrigger
            render={
              <Button
                variant="ghost"
                size="icon-sm"
                aria-label="More actions"
                title={`More actions for user ${email}`}
                data-testid={`${prefix}__actions-${id}`}
              >
                <MoreHorizontal className="size-3.5" aria-hidden="true" />
                <span className="sr-only">More actions</span>
              </Button>
            }
          />
          <DropdownMenuContent align="end" className="min-w-36">
            <DropdownMenuItem
              variant="destructive"
              onClick={() => setDeleteOpen(true)}
              data-testid={`${prefix}__delete-${id}`}
            >
              <Trash2 aria-hidden="true" />
              Delete
            </DropdownMenuItem>
          </DropdownMenuContent>
        </DropdownMenu>
        <DeleteUserButton
          id={id}
          email={email}
          onDeleted={onUserDeleted}
          onError={(problem) => onUserDeleteFailed(id, problem)}
          open={deleteOpen}
          onOpenChange={setDeleteOpen}
        />
      </Can>
    </div>
  );
}
