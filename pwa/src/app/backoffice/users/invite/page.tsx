import type { Metadata } from "next";
import Link from "next/link";
import { ChevronLeft } from "lucide-react";
import { EmptyState } from "@/components/erpify";
import { Can } from "@/context/shared/access/infrastructure/ui";
import { Permission } from "@/context/shared/access/domain/Permission";
import { safeHref } from "@/context/shared/navigation/domain/safeHref";
import { InviteUserForm } from "../_components/InviteUserForm";
import { userRoutes } from "../_lib/userRoutes";

// Next's route announcer reads `document.title` and speaks only when it changes, so a route
// inheriting the root title arrives silently for assistive technology. This is what announces
// the surface before the form takes the focus.
export const metadata: Metadata = {
  title: "Invite user",
};

export default function InviteUserPage() {
  return (
    <Can
      permission={Permission.USERS_INVITE}
      fallback={
        <EmptyState
          variant="permission-denied"
          heading="Access denied"
          description="You don't have permission to invite users."
        />
      }
    >
      <div
        className="users-invite mx-auto w-full max-w-screen-md space-y-4 sm:space-y-6"
        data-testid="users-invite"
      >
        <header className="users-invite__header space-y-2" data-testid="users-invite__header">
          <Link
            href={safeHref(userRoutes.list)}
            className="text-muted-foreground hover:text-foreground inline-flex items-center gap-1 text-xs"
            aria-label="Back to users"
            title="Back to users"
            data-testid="users-invite__back-link"
          >
            <ChevronLeft className="size-3" aria-hidden="true" />
            Back to users
          </Link>
          <h1
            className="text-foreground text-xl font-semibold tracking-tight sm:text-2xl"
            data-testid="users-invite__title"
          >
            Invite user
          </h1>
          <p className="text-muted-foreground text-sm" data-testid="users-invite__subtitle">
            Invite a new member by email and assign their roles. The alta is an invitation — they
            set their own password from the emailed link.
          </p>
        </header>

        <InviteUserForm />
      </div>
    </Can>
  );
}
