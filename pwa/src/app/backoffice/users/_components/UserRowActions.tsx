"use client";

import { useRef, useState } from "react";
import { MailX, MoreHorizontal } from "lucide-react";
import { CopyButton } from "@/components/erpify";
import { Button } from "@/components/ui/button";
import {
  DropdownMenu,
  DropdownMenuContent,
  DropdownMenuItem,
  DropdownMenuTrigger,
} from "@/components/ui/dropdown-menu";
import { cn } from "@/components/cn";
import { UserStatus } from "@/context/shared/access/domain/UserStatus";
import { Permission } from "@/context/shared/access/domain/Permission";
import { useCan } from "@/context/shared/access/application/useCan";
import type { ProblemDetails } from "@/context/shared/error/domain/ProblemDetails";
import { RevokeInvitationButton } from "./RevokeInvitationButton";

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
  /** Lifecycle status of the row — only an INVITED identity has an invitation to withdraw. */
  status: UserStatus;
  /** Drives the testid prefix (`users-table__…` / `users-cards__…`). */
  surface: UserRowActionsSurface;
  /** Hover/focus reveal scope for the copy control. */
  reveal?: UserRowActionsReveal;
  /** Runs after a successful revocation; the list re-reads the register. */
  onInvitationRevoked: () => void;
  /** Failed revocation → the list anchors the problem in its persistent error surface. */
  onRevokeFailed: (problem: ProblemDetails) => void;
  className?: string;
}

/**
 * Per-row action cluster shared by the users table, cards and stacked list. Copying the id is the
 * non-destructive direct control; the one consequential act a row affords is withdrawing a still-pending
 * invitation, demoted into a `⋯` overflow menu so it is never a mis-click away from Copy. The menu item opens
 * a parent-controlled confirmation dialog (a menu item cannot itself be a dialog trigger without focus
 * conflicts, hence the controlled-open handoff).
 *
 * The remaining capabilities of an identity (change status, change roles, GDPR erase) stay on the detail
 * surface: they need the whole record in view to be decided, which a row does not give. Revocation is the
 * exception because it is incident response — the register is where a wrong invitation is spotted.
 *
 * The menu item renders only for an INVITED row whose session holds `users.revokeInvitation`. Both conditions
 * are rendering convenience: the API enforces the permission and answers 404 when nothing is revocable, and
 * that remains the only control. The status condition also retires the action without any client-side
 * bookkeeping — a revocation withdraws the identity to REVOKED, so the re-read that follows removes the menu
 * on its own.
 *
 * That retirement is why the cluster is the dialog's closing focus target on success: the control the dialog
 * would normally return to has just stopped existing, and nothing else would catch the focus, leaving a
 * keyboard user at the top of the document. The cluster survives every status, sits where the operator was
 * acting, and holds the row's remaining control.
 */
export function UserRowActions({
  id,
  email,
  status,
  surface,
  reveal = "none",
  onInvitationRevoked,
  onRevokeFailed,
  className,
}: Readonly<UserRowActionsProps>) {
  const [revokeOpen, setRevokeOpen] = useState(false);
  const clusterRef = useRef<HTMLDivElement>(null);
  const mayRevoke = useCan(Permission.USERS_REVOKE_INVITATION);
  const revocable = mayRevoke && status === UserStatus.INVITED;
  const prefix = `users-${surface}`;

  return (
    <div
      ref={clusterRef}
      // Focus target, never a tab stop: the closing dialog aims here, and a real browser lands on the first
      // tabbable control inside — the container itself only catches the focus when there is none.
      //
      // `__rowactions-` and not `__row-actions-`: the latter is a prefix match away from the row's own
      // `__row-<id>`, so any `^=` selector over rows counts this element as a second row. Banks needs no
      // equivalent — its delete drops the row, so focus moves to a neighbour instead of staying in place.
      tabIndex={-1}
      className={cn("users-row-actions flex items-center gap-0.5 outline-none", className)}
      data-testid={`${prefix}__rowactions-${id}`}
    >
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
      {revocable ? (
        <>
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
            <DropdownMenuContent align="end" className="min-w-44">
              <DropdownMenuItem
                variant="destructive"
                onClick={() => setRevokeOpen(true)}
                data-testid={`${prefix}__revoke-${id}`}
              >
                <MailX aria-hidden="true" />
                Revoke invitation
              </DropdownMenuItem>
            </DropdownMenuContent>
          </DropdownMenu>
          <RevokeInvitationButton
            userId={id}
            email={email}
            open={revokeOpen}
            onOpenChange={setRevokeOpen}
            onRevoked={onInvitationRevoked}
            onError={onRevokeFailed}
            focusOnRevoked={clusterRef}
          />
        </>
      ) : null}
    </div>
  );
}
