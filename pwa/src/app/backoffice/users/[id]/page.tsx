import type { Metadata } from "next";
import { EmptyState } from "@/components/erpify";
import { Can } from "@/context/shared/access/infrastructure/ui";
import { Permission } from "@/context/shared/access/domain/Permission";
import { UserDetailView } from "../_components/UserDetailView";

export const metadata: Metadata = {
  title: "User detail",
};

export default function UserDetailPage() {
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
      <UserDetailView />
    </Can>
  );
}
