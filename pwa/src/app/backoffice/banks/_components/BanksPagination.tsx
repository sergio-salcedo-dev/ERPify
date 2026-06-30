"use client";

import { KeysetPagination, type KeysetPaginationLabels } from "@/components/erpify";
import { BANKS_PAGE_SIZE_OPTIONS, type BanksPageSize } from "../_lib/paginate";

const LABELS: KeysetPaginationLabels = {
  nav: "Banks pagination",
  pageSize: "Items per page",
  prev: { text: "Prev", label: "Previous page" },
  next: { text: "Next", label: "Next page" },
};

interface BanksPaginationProps {
  pageSize: BanksPageSize;
  hasPrev: boolean;
  hasNext: boolean;
  onPrev: () => void;
  onNext: () => void;
  onPageSizeChange: (next: BanksPageSize) => void;
}

export function BanksPagination(props: Readonly<BanksPaginationProps>) {
  return (
    <KeysetPagination<BanksPageSize>
      testId="banks-pagination"
      labels={LABELS}
      pageSizeOptions={BANKS_PAGE_SIZE_OPTIONS}
      {...props}
    />
  );
}
