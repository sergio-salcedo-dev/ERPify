"use client";

import { useEffect, useState } from "react";
import { container } from "@/context/shared/infrastructure/DependencyInjection/Container";
import type { DebugToken } from "@/context/shared/debug-token/domain/DebugToken";
import type { DebugTokenObserver } from "@/context/shared/debug-token/domain/DebugTokenObserver";

/**
 * Subscribes to the latest Symfony profiler {@link DebugToken}. Returns `null`
 * until the first `/api/*` response carrying a token. `observer` is injectable
 * for tests; by default it resolves the container's singleton adapter.
 */
export function useLatestDebugToken(
  observer: DebugTokenObserver = container.get<DebugTokenObserver>("DebugTokenObserver"),
): DebugToken | null {
  const [token, setToken] = useState<DebugToken | null>(null);

  useEffect(() => {
    return observer.subscribe(setToken);
  }, [observer]);

  return token;
}
