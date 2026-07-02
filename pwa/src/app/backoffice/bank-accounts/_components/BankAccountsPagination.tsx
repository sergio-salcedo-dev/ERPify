"use client";

import { KeysetPagination, type KeysetPaginationLabels } from "@/components/erpify";
import { BANK_ACCOUNTS_PAGE_SIZE_OPTIONS, type BankAccountsPageSize } from "../_lib/paginate";

const LABELS: KeysetPaginationLabels = {
  nav: "Bank accounts pagination",
  pageSize: "Items per page",
  prev: { text: "Prev", label: "Previous page" },
  next: { text: "Next", label: "Next page" },
};

interface BankAccountsPaginationProps {
  pageSize: BankAccountsPageSize;
  hasPrev: boolean;
  hasNext: boolean;
  onPrev: () => void;
  onNext: () => void;
  onPageSizeChange: (next: BankAccountsPageSize) => void;
}

export function BankAccountsPagination(props: Readonly<BankAccountsPaginationProps>) {
  return (
    <KeysetPagination<BankAccountsPageSize>
      testId="bank-accounts-pagination"
      labels={LABELS}
      pageSizeOptions={BANK_ACCOUNTS_PAGE_SIZE_OPTIONS}
      {...props}
    />
  );
}
