"use client";

import { ChevronLeft, ChevronRight } from "lucide-react";
import { Button } from "@/components/ui/button";
import {
  AUDIT_PAGE_SIZE_OPTIONS,
  isAuditPageSize,
  type AuditPageSize,
} from "@/app/backoffice/audit/_lib/auditPaginate";

interface AuditPaginationProps {
  pageSize: AuditPageSize;
  hasPrev: boolean;
  hasNext: boolean;
  onPrev: () => void;
  onNext: () => void;
  onPageSizeChange: (next: AuditPageSize) => void;
}

/**
 * Cursor-only keyset pagination, the `BanksPagination` pattern: the prev/next pair is ALWAYS rendered
 * and `disabled` when its envelope link is null, never hidden (the documented D-A11y exception). No
 * page number — keyset navigation is purely directional. Changing filters/order resets the cursor
 * upstream, so this component only ever follows the server-issued links.
 */
export function AuditPagination({
  pageSize,
  hasPrev,
  hasNext,
  onPrev,
  onNext,
  onPageSizeChange,
}: Readonly<AuditPaginationProps>) {
  return (
    <nav
      className="audit-pagination mt-3 flex flex-col items-stretch gap-3 sm:flex-row sm:items-center sm:justify-between"
      aria-label="Paginación de auditoría"
      data-testid="audit-pagination"
    >
      <label className="audit-pagination__page-size text-muted-foreground flex items-center gap-2 text-xs">
        <span className="audit-pagination__page-size-label">Entradas por página</span>
        <select
          className="audit-pagination__page-size-select border-border bg-background text-foreground focus-visible:ring-ring h-7 rounded-md border px-2 text-xs focus-visible:ring-2 focus-visible:outline-none"
          value={pageSize}
          onChange={(event) => {
            const next = Number(event.target.value);
            if (isAuditPageSize(next)) onPageSizeChange(next);
          }}
          aria-label="Entradas por página"
          title="Entradas por página"
          data-testid="audit-pagination__page-size"
        >
          {AUDIT_PAGE_SIZE_OPTIONS.map((option) => (
            <option key={option} value={option}>
              {option}
            </option>
          ))}
        </select>
      </label>

      <div className="audit-pagination__controls flex items-center justify-end gap-2">
        <Button
          type="button"
          variant="outline"
          size="sm"
          className="audit-pagination__prev"
          onClick={onPrev}
          disabled={!hasPrev}
          aria-label="Página anterior"
          title="Página anterior"
          data-testid="audit-pagination__prev"
        >
          <ChevronLeft className="size-3.5" aria-hidden="true" />
          <span className="hidden sm:inline">Anterior</span>
        </Button>
        <Button
          type="button"
          variant="outline"
          size="sm"
          className="audit-pagination__next"
          onClick={onNext}
          disabled={!hasNext}
          aria-label="Página siguiente"
          title="Página siguiente"
          data-testid="audit-pagination__next"
        >
          <span className="hidden sm:inline">Siguiente</span>
          <ChevronRight className="size-3.5" aria-hidden="true" />
        </Button>
      </div>
    </nav>
  );
}
