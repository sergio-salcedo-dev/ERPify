import type { Metadata } from "next";
import { EmptyState } from "@/components/erpify";
import { Can } from "@/context/shared/access/infrastructure/ui";
import { Permission } from "@/context/shared/access/domain/Permission";
import { UsersListView } from "./_components/UsersListView";

export const metadata: Metadata = {
  title: "Users",
};

export default function UsersListPage() {
  return (
    <Can
      permission={Permission.USERS_READ}
      fallback={
        <EmptyState
          variant="permission-denied"
          heading="Access denied"
          description="You don't have permission to view users."
        />
      }
    >
      <UsersListView />
    </Can>
  );
}
