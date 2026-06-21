"use client";

import Link from "next/link";
import { ChevronLeft } from "lucide-react";
import { PersistenceAction } from "@/context/shared/view-state/domain/ViewState";
import { EmptyState } from "@/components/erpify";
import { Can } from "@/context/shared/access/infrastructure/ui";
import { Permission } from "@/context/shared/access/domain/Permission";
import { userRoutes } from "../_lib/userRoutes";
import { UserForm } from "../_components/UserForm";

export default function NewUserPage() {
  return (
    <div
      className="users-new mx-auto w-full max-w-screen-md space-y-4 sm:space-y-6"
      data-testid="users-new"
    >
      <header className="users-new__header space-y-2" data-testid="users-new__header">
        <Link
          href={userRoutes.list}
          className="text-muted-foreground hover:text-foreground inline-flex items-center gap-1 text-xs"
          aria-label="Back to users"
          title="Back to users"
          data-testid="users-new__back-link"
        >
          <ChevronLeft className="size-3" aria-hidden="true" />
          Back to users
        </Link>
        <h1
          className="text-foreground text-xl font-semibold tracking-tight sm:text-2xl"
          data-testid="users-new__title"
        >
          New user
        </h1>
        <p className="text-muted-foreground text-sm" data-testid="users-new__subtitle">
          Create a new user and grant their access.
        </p>
      </header>

      <Can
        permission={Permission.USERS_WRITE}
        fallback={
          <EmptyState
            variant="first-run"
            heading="Access denied"
            description="You don't have permission to create users."
          />
        }
      >
        <UserForm mode={PersistenceAction.CREATING} />
      </Can>
    </div>
  );
}
