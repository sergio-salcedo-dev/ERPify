"use client";

import { useEffect, useState } from "react";

/**
 * Reports browser connectivity from `navigator.onLine`, kept in sync via the
 * `online` / `offline` window events. SSR-safe: the first render (server + the
 * client's hydration pass) assumes online, so no connectivity band flashes
 * before the browser reports a real state; the effect reconciles with the true
 * value right after mount.
 */
export function useOnlineStatus(): boolean {
  const [online, setOnline] = useState(true);

  useEffect(() => {
    const sync = (): void => setOnline(navigator.onLine);
    sync();
    window.addEventListener("online", sync);
    window.addEventListener("offline", sync);
    return () => {
      window.removeEventListener("online", sync);
      window.removeEventListener("offline", sync);
    };
  }, []);

  return online;
}
