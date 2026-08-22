/**
 * Layout-stable loading placeholder for the timeline: a day-divider header plus a handful of compact
 * skeleton rows, so the column rhythm is present before data arrives and the swap to real rows does
 * not shift the page. Purely decorative — the live region / "still loading…" copy lives in the page's
 * `<AsyncBoundary>`.
 */
interface AuditTimelineSkeletonProps {
  rows?: number;
  testId?: string;
}

export function AuditTimelineSkeleton({ rows = 8, testId }: Readonly<AuditTimelineSkeletonProps>) {
  return (
    <div
      className="audit-timeline-skeleton border-border overflow-hidden rounded-md border"
      data-testid={testId}
      aria-hidden="true"
    >
      <div className="bg-bg-subtle/60 border-border border-b px-3 py-1.5">
        <div className="bg-muted h-3 w-40 animate-pulse rounded" />
      </div>
      <ul className="divide-border divide-y">
        {Array.from({ length: rows }, (_, index) => (
          <li key={index} className="flex h-9 items-center gap-4 px-3">
            <div className="bg-muted h-3 w-20 flex-none animate-pulse rounded" />
            <div className="bg-muted h-4 w-16 flex-none animate-pulse rounded-full" />
            <div className="bg-muted h-3 flex-1 animate-pulse rounded" />
            <div className="bg-muted h-3 w-24 flex-none animate-pulse rounded" />
          </li>
        ))}
      </ul>
    </div>
  );
}
