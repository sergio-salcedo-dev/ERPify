"use client";

import { KeysetPagination, type KeysetPaginationLabels } from "@/components/erpify";
import {
  AUDIT_PAGE_SIZE_OPTIONS,
  type AuditPageSize,
} from "@/app/backoffice/audit/_lib/auditPaginate";

const LABELS: KeysetPaginationLabels = {
  nav: "Audit pagination",
  pageSize: "Entries per page",
  prev: { text: "Anterior", label: "Previous page" },
  next: { text: "Siguiente", label: "Next page" },
};

interface AuditPaginationProps {
  pageSize: AuditPageSize;
  hasPrev: boolean;
  hasNext: boolean;
  onPrev: () => void;
  onNext: () => void;
  onPageSizeChange: (next: AuditPageSize) => void;
}

export function AuditPagination(props: Readonly<AuditPaginationProps>) {
  return (
    <KeysetPagination<AuditPageSize>
      testId="audit-pagination"
      labels={LABELS}
      pageSizeOptions={AUDIT_PAGE_SIZE_OPTIONS}
      {...props}
    />
  );
}
