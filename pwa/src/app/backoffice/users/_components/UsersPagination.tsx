"use client";

import { KeysetPagination, type KeysetPaginationLabels } from "@/components/erpify";
import { USERS_PAGE_SIZE_OPTIONS, type UsersPageSize } from "../_lib/paginate";

const LABELS: KeysetPaginationLabels = {
  nav: "Users pagination",
  pageSize: "Items per page",
  prev: { text: "Prev", label: "Previous page" },
  next: { text: "Next", label: "Next page" },
};

interface UsersPaginationProps {
  pageSize: UsersPageSize;
  hasPrev: boolean;
  hasNext: boolean;
  onPrev: () => void;
  onNext: () => void;
  onPageSizeChange: (next: UsersPageSize) => void;
}

export function UsersPagination(props: Readonly<UsersPaginationProps>) {
  return (
    <KeysetPagination<UsersPageSize>
      testId="users-pagination"
      labels={LABELS}
      pageSizeOptions={USERS_PAGE_SIZE_OPTIONS}
      {...props}
    />
  );
}
