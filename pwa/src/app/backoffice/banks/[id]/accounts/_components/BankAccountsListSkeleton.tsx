interface BankAccountsListSkeletonProps {
  /** Number of placeholder rows. */
  rows?: number;
}

const SKELETON_ROW_KEYS = ["a", "b", "c", "d", "e", "f", "g", "h"] as const;

const SKELETON_TESTID = "bank-accounts__skeleton";

/**
 * Row-shaped loading placeholder matching the accounts table layout
 * (Holder · IBAN · Alias · Currency · Status). Decorative: the wrapping
 * `AsyncBoundary` already exposes `role="status"`/`aria-busy`, so this is
 * `aria-hidden`.
 */
export function BankAccountsListSkeleton({ rows = 6 }: Readonly<BankAccountsListSkeletonProps>) {
  const keys = SKELETON_ROW_KEYS.slice(0, Math.min(rows, SKELETON_ROW_KEYS.length));

  return (
    <div
      className="bank-accounts__skeleton border-border overflow-hidden rounded-md border"
      data-testid={SKELETON_TESTID}
      aria-hidden="true"
    >
      {keys.map((key) => (
        <div
          key={key}
          className="border-border flex animate-pulse items-center gap-4 border-b p-3 last:border-b-0"
        >
          <div className="bg-muted h-4 w-[26%] rounded" />
          <div className="bg-muted h-4 w-[34%] rounded" />
          <div className="bg-muted hidden h-4 w-[18%] rounded md:block" />
          <div className="bg-muted h-4 w-[10%] rounded" />
          <div className="bg-muted h-6 w-[12%] rounded-full" />
        </div>
      ))}
    </div>
  );
}
