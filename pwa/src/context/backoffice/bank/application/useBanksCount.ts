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
 * is therefore one line: **the total is what the last read ISSUED returned, and unknown if that read
 * failed** — every failure path, the unresolvable dependency included, so the rule holds without an
 * exception a reader has to carry.
 *
 * "Issued" rather than "settled" is the load-bearing word. `refresh` is wired to realtime create,
 * delete and reconnect, so several reads are in flight during a bulk change and they can settle out of
 * order. Without the epoch a late *rejection* would erase a newer correct total — worse than the stale
 * number the `null` state exists to prevent, because it discards a value that was right.
 */
export function useBanksCount(): { count: number | null; refresh: () => void } {
  const [count, setCount] = useState<number | null>(null);
  const mountedRef = useRef(true);
  const epochRef = useRef(0);

  const refresh = useCallback(() => {
    const epoch = ++epochRef.current;
    // Answers from a read a newer one has already superseded are dropped, whichever way they settle.
    const isCurrent = (): boolean => mountedRef.current && epoch === epochRef.current;

    let useCase: CountBanks;
    try {
      useCase = container.get<CountBanks>("BackOfficeCountBanks");
    } catch {
      // The dependency isn't wired (e.g. a list spec that mocks only the search/delete tokens). That
      // is a read that failed, so it reports unknown like any other rather than leaving the previous
      // number standing — on the first call the state is already `null` and this changes nothing.
      if (isCurrent()) setCount(null);

      return;
    }
    useCase
      .run()
      .then((total) => {
        if (isCurrent()) setCount(total);
      })
      .catch(() => {
        // Auxiliary read: a failed fetch must not surface anywhere — but it must not be reported as
        // a total either, so the header goes back to making no claim.
        if (isCurrent()) setCount(null);
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
