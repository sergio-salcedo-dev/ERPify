"use client";

import { useCallback, useEffect, useRef, useState } from "react";
import { container } from "@/context/shared/dependency-injection/infrastructure/Container";
import type { CountBanks } from "./CountBanks";

/**
 * Resolves and reads the banks total for the list header. The count is
 * auxiliary, so a resolution or fetch failure is swallowed (the header simply
 * keeps the last known value, defaulting to 0) — it must never crash or block
 * the list. Returns the current total plus a `refresh` the caller wires into
 * realtime create/delete so the header stays in step with the table.
 */
export function useBanksCount(): { count: number; refresh: () => void } {
  const [count, setCount] = useState(0);
  const mountedRef = useRef(true);

  const refresh = useCallback(() => {
    let useCase: CountBanks;
    try {
      useCase = container.get<CountBanks>("BackOfficeCountBanks");
    } catch {
      // The dependency isn't wired (e.g. a list spec that mocks only the
      // search/delete tokens) — leave the total at its default.
      return;
    }
    useCase
      .run()
      .then((total) => {
        if (mountedRef.current) setCount(total);
      })
      .catch(() => {
        // Auxiliary read: a failed fetch must not surface anywhere.
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
