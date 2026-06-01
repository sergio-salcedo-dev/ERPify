import type { BanksView } from "./BanksViewToggle";

interface BanksListSkeletonProps {
  view: BanksView;
  /** Number of placeholder rows/cards. */
  rows?: number;
}

const SKELETON_ROW_KEYS = ["a", "b", "c", "d", "e", "f", "g", "h"] as const;

const SKELETON_TESTID = "banks-list__skeleton";

/**
 * List-shaped loading placeholder. Decorative: the wrapping
 * `AsyncBoundary` already exposes `role="status"`/`aria-busy`, so this is
 * `aria-hidden`.
 */
export function BanksListSkeleton({ view, rows = 6 }: Readonly<BanksListSkeletonProps>) {
  const keys = SKELETON_ROW_KEYS.slice(0, Math.min(rows, SKELETON_ROW_KEYS.length));

  if (view === "cards") {
    return (
      <ul
        className="banks-list__skeleton grid list-none grid-cols-1 gap-4 p-0 sm:grid-cols-2 lg:grid-cols-3 2xl:grid-cols-4"
        data-testid={SKELETON_TESTID}
        aria-hidden="true"
      >
        {keys.map((key) => (
          <li key={key} className="border-border bg-card animate-pulse rounded-lg border p-4">
            <div className="bg-muted h-4 w-2/3 rounded" />
            <div className="bg-muted mt-2 h-3 w-1/3 rounded" />
            <div className="bg-muted mt-4 h-3 w-1/2 rounded" />
            <div className="bg-muted mt-2 h-3 w-2/5 rounded" />
          </li>
        ))}
      </ul>
    );
  }

  return (
    <div
      className="banks-list__skeleton border-border overflow-hidden rounded-md border"
      data-testid={SKELETON_TESTID}
      aria-hidden="true"
    >
      {keys.map((key) => (
        <div
          key={key}
          className="border-border flex animate-pulse items-center gap-4 border-b p-3 last:border-b-0"
        >
          <div className="bg-muted h-4 w-24 rounded" />
          <div className="bg-muted h-4 flex-1 rounded" />
          <div className="bg-muted hidden h-4 w-32 rounded md:block" />
          <div className="bg-muted h-7 w-20 rounded" />
        </div>
      ))}
    </div>
  );
}
