"use client";

import { useEffect, useRef } from "react";
import { telemetry } from "@/context/shared/infrastructure/Observability";
import { apiScope } from "@/context/shared/domain/Observability/TelemetryScope";
import type { DebugTokenObserver } from "@/context/shared/domain/DebugToken/DebugTokenObserver";
import { useLatestDebugToken } from "./useLatestDebugToken";

/** Dev-only Symfony endpoint serving the toolbar loader (routed via FrankenPHP). */
const WDT_PATH = "/_dev/wdt-loader";

/**
 * Recreates a parsed node tree into `host`, replacing each `<script>` with a
 * freshly-created element so the browser executes it (parsed/cloned scripts are
 * inert by spec). Avoids `innerHTML` / `dangerouslySetInnerHTML`: the loader is
 * dev-only, same-origin, trusted Symfony output.
 */
function mountFragment(host: HTMLElement, html: string): void {
  while (host.firstChild) host.removeChild(host.firstChild);
  const parsed = new DOMParser().parseFromString(html, "text/html");
  for (const node of Array.from(parsed.body.childNodes)) {
    host.appendChild(reviveNode(node));
  }
}

function reviveNode(node: Node): Node {
  if (node.nodeName === "SCRIPT") {
    const original = node as HTMLScriptElement;
    const script = document.createElement("script");
    for (const attr of Array.from(original.attributes)) {
      script.setAttribute(attr.name, attr.value);
    }
    script.textContent = original.textContent;
    return script;
  }
  const clone = node.cloneNode(false);
  for (const child of Array.from(node.childNodes)) {
    clone.appendChild(reviveNode(child));
  }
  return clone;
}

/**
 * Dev-only floating Symfony Web Debug Toolbar for the real PWA. Mounted once in
 * the root layout behind `isDevToolsAvailable()`. On each new profiler token it
 * loads the Symfony toolbar loader and mounts it fixed to the viewport bottom;
 * the loader then pulls the toolbar markup and styles itself.
 */
export function SymfonyDebugToolbar({
  observer,
}: {
  observer?: DebugTokenObserver;
}): React.ReactElement | null {
  const debugToken = useLatestDebugToken(observer);
  const hostRef = useRef<HTMLDivElement>(null);
  const token = debugToken?.token ?? null;

  useEffect(() => {
    const host = hostRef.current;
    if (!host || !token) return;

    let cancelled = false;
    fetch(`${WDT_PATH}/${encodeURIComponent(token)}`, { cache: "no-store" })
      .then((res) => {
        if (!res.ok) throw new Error(`${WDT_PATH} responded ${res.status}`);
        return res.text();
      })
      .then((html) => {
        if (!cancelled && hostRef.current) mountFragment(hostRef.current, html);
      })
      .catch((cause: unknown) => {
        telemetry.warn("Failed to load the Symfony debug toolbar loader", {
          scope: apiScope("wdt"),
          cause,
        });
      });

    return () => {
      cancelled = true;
    };
  }, [token]);

  if (!token) return null;

  return (
    <div
      ref={hostRef}
      data-testid="dev-tools__symfony-toolbar"
      className="symfony-debug-toolbar"
      style={{ position: "fixed", left: 0, right: 0, bottom: 0, zIndex: 2147483646 }}
    />
  );
}
