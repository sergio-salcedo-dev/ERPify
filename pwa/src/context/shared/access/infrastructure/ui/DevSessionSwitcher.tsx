"use client";

import { ShieldHalf } from "lucide-react";
import { useSession } from "../../application/useSession";
import { ALL_ROLES, type Role } from "../../domain/Role";
import { UserStatus } from "../../domain/UserStatus";
import { ALL_PERMISSIONS, PERMISSION_WILDCARD, type HeldPermission } from "../../domain/Permission";
import {
  DropdownMenu,
  DropdownMenuContent,
  DropdownMenuTrigger,
  DropdownMenuLabel,
  DropdownMenuRadioGroup,
  DropdownMenuRadioItem,
  DropdownMenuSeparator,
  DropdownMenuCheckboxItem,
} from "@/components/ui/dropdown-menu";
import { Button } from "@/components/ui/button";

/**
 * Dev/QA only (mounted behind isDevToolsAvailable). Mutates the mocked session
 * so IAM guards are demonstrable: switch role, status, and permission breadth.
 */
export function DevSessionSwitcher() {
  const { session, override } = useSession();
  if (!session) return null;
  const role = session.roles[0];
  const wildcard = session.permissions.includes(PERMISSION_WILDCARD);

  const setRole = (next: Role): void => override({ roles: [next], user: { roles: [next] } });
  const setStatus = (next: string): void => override({ user: { status: next as UserStatus } });
  const setWildcard = (on: boolean): void => {
    const permissions: HeldPermission[] = on ? [PERMISSION_WILDCARD] : [...ALL_PERMISSIONS];
    override({ permissions, user: { permissions } });
  };

  return (
    <DropdownMenu>
      <DropdownMenuTrigger
        render={
          <Button
            variant="ghost"
            size="icon-sm"
            aria-label="Dev session switcher"
            title="Switch mocked role / status / permissions (dev only)"
            data-testid="dev-session-switcher"
          >
            <ShieldHalf className="size-4" aria-hidden="true" />
            <span className="sr-only">Dev session switcher</span>
          </Button>
        }
      />
      <DropdownMenuContent align="end" className="w-56">
        <DropdownMenuRadioGroup value={role} onValueChange={(v) => setRole(v as Role)}>
          <DropdownMenuLabel>Role</DropdownMenuLabel>
          {ALL_ROLES.map((r) => (
            <DropdownMenuRadioItem key={r} value={r}>
              {r}
            </DropdownMenuRadioItem>
          ))}
        </DropdownMenuRadioGroup>
        <DropdownMenuSeparator />
        <DropdownMenuRadioGroup value={session.user.status} onValueChange={setStatus}>
          <DropdownMenuLabel>Status</DropdownMenuLabel>
          {Object.values(UserStatus).map((s) => (
            <DropdownMenuRadioItem key={s} value={s}>
              {s}
            </DropdownMenuRadioItem>
          ))}
        </DropdownMenuRadioGroup>
        <DropdownMenuSeparator />
        <DropdownMenuCheckboxItem checked={wildcard} onCheckedChange={setWildcard}>
          All permissions (*)
        </DropdownMenuCheckboxItem>
      </DropdownMenuContent>
    </DropdownMenu>
  );
}
