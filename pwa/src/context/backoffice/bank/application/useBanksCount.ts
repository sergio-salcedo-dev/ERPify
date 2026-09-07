"use client";

import { useCallback, useEffect, useRef, useState } from "react";
import { container } from "@/context/shared/dependency-injection/infrastructure/Container";
import type { CountBanks } from "./CountBanks";

/**
 * Resolves and reads the banks total for the list header. The count is auxiliary, so a resolution or
 * fetch failure is swallowed — it must never crash or block the list. Returns the current total plus
 * a `refresh` the caller wires into realtime create/delete so the header stays in step with the table.
 *
 * `null` is "unknown", and it is a distinct value from `0` rather than a spelling of it. Defaulting a
 * failed read to `0` renders a falsehood with exactly the confidence of a true total — a header
 * reading "0 banks total" above a populated table — and the caller cannot tell the two apart. The rule
 * is therefore one line: the total is what the LAST read returned, and unknown if that read failed.
 * A failed `refresh` drops back to unknown rather than keeping the previous number, because the
 * refresh is triggered by a realtime create/delete: the cached value is not merely stale, it is known
 * to be wrong by exactly the event that asked for it.
 */
export function useBanksCount(): { count: number | null; refresh: () => void } {
  const [count, setCount] = useState<number | null>(null);
  const mountedRef = useRef(true);

  const refresh = useCallback(() => {
    let useCase: CountBanks;
    try {
      useCase = container.get<CountBanks>("BackOfficeCountBanks");
    } catch {
      // The dependency isn't wired (e.g. a list spec that mocks only the
      // search/delete tokens) — the total stays unknown.
      return;
    }
    useCase
      .run()
      .then((total) => {
        if (mountedRef.current) setCount(total);
      })
      .catch(() => {
        // Auxiliary read: a failed fetch must not surface anywhere — but it must not be reported as
        // a total either, so the header goes back to making no claim.
        if (mountedRef.current) setCount(null);
      });
  }, []);

  useEffect(() => {
    mountedRef.current = true;
    refresh();
    return () => {
      mountedRef.current = false;
    };
  }, [refresh]);

  return { count, refresh };
}
