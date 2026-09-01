"use client";

import { useEffect, useRef } from "react";
import { telemetry } from "@/context/shared/observability/infrastructure";
import { apiScope } from "@/context/shared/observability/domain/TelemetryScope";
import type { DebugTokenObserver } from "@/context/shared/debug-token/domain/DebugTokenObserver";
import { useLatestDebugToken } from "./useLatestDebugToken";

/** Dev-only Symfony endpoint serving the toolbar loader (routed via FrankenPHP). */
const WDT_PATH = "/_dev/wdt-loader";

/**
 * Recreates a parsed node tree into `host`, replacing each `<script>` with a
 * freshly-created element so the browser executes it (parsed/cloned scripts are
 * inert by spec). Avoids `innerHTML` / `dangerouslySetInnerHTML`: the loader is
 * dev-only, same-origin, trusted Symfony output.
 */
function mountFragment(host: HTMLElement, html: string): number {
  while (host.firstChild) host.firstChild.remove();
  const parsed = new DOMParser().parseFromString(html, "text/html");
  // The loader is a document fragment, not a body: it leads with the toolbar's
  // `<link rel="stylesheet">`, and the HTML parser hoists a leading `<link>` —
  // plus the empty `<script>` upstream emits beside it — into `<head>`, never
  // `<body>`. Reading `body` alone therefore drops the toolbar's entire
  // stylesheet and paints its raw markup over a viewport-wide fixed host. Head
  // then body is the fragment back in the order upstream sent it.
  //
  // That empty `<script>` is upstream's way of making the PARSER block on the
  // sheet, and it is inert here: nothing on this path parses, and a `<link>` its
  // node document's parser did not create is not render-blocking. So the markup
  // can paint for one round trip before the sheet resolves. Ordering the nodes
  // does not claim to close that window — it only keeps the source order.
  let mountedElements = 0;
  for (const node of [...parsed.head.childNodes, ...parsed.body.childNodes]) {
    const revived = reviveNode(node);
    watchStylesheetFailure(revived);
    host.appendChild(revived);
    if (node.nodeType === Node.ELEMENT_NODE) mountedElements += 1;
  }
  return mountedElements;
}

/**
 * Reports a mounted stylesheet that never loads. The toolbar's sheet is served by
 * a controller that refuses when the profiler is disabled, while the loader that
 * names it renders without consulting the profiler — so a 200 loader can carry a
 * link whose target 404s, which reproduces the unstyled toolbar with no signal at
 * all. Silence is what made that state expensive to diagnose the first time.
 */
function watchStylesheetFailure(node: Node): void {
  if (!(node instanceof HTMLLinkElement) || node.rel !== "stylesheet") return;
  node.addEventListener(
    "error",
    () => {
      telemetry.warn("The Symfony debug toolbar stylesheet failed to load", {
        scope: apiScope("wdt"),
        cause: node.href,
      });
    },
    { once: true },
  );
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
}: Readonly<{
  observer?: DebugTokenObserver;
}>): React.ReactElement | null {
  const debugToken = useLatestDebugToken(observer);
  const hostRef = useRef<HTMLDivElement>(null);
  const mountedRef = useRef(false);
  const token = debugToken?.token ?? null;

  useEffect(() => {
    const host = hostRef.current;
    // Load the toolbar loader exactly once. Symfony's sfjs installs *global*
    // XHR/fetch hooks that render every subsequent request into its own AJAX
    // panel, so re-fetching + re-wiping the host on each new profiler token is
    // redundant and racy: wiping the toolbar DOM out from under those still-live
    // handlers makes the next in-flight AJAX completion deref a detached node
    // (`renderAjaxRequests` reading `.style` of null). A stable DOM avoids that;
    // the latch only flips on a successful mount, so a failed load still retries
    // on the next token.
    //
    // Conscious trade-off: the toolbar's main bar stays bound to the session's
    // first request (sfjs's AJAX panel still tracks later ones). For a dev-only
    // tool that beats re-introducing unmount/remount churn around Symfony JS we
    // don't own — which is what caused the crash.
    if (!host || !token || mountedRef.current) return;

    let cancelled = false;
    fetch(`${WDT_PATH}/${encodeURIComponent(token)}`, { cache: "no-store" })
      .then((res) => {
        if (!res.ok) throw new Error(`${WDT_PATH} responded ${res.status}`);
        return res.text();
      })
      .then((html) => {
        if (cancelled || mountedRef.current || !hostRef.current) return;
        // Latch on a mount that produced something. A 200 carrying an empty or
        // non-HTML body parses to zero elements, and latching on it would discard
        // every later token for the rest of the session — the toolbar gone, with
        // no retry and nothing logged.
        if (mountFragment(hostRef.current, html) === 0) {
          telemetry.warn("The Symfony debug toolbar loader returned no elements", {
            scope: apiScope("wdt"),
          });
          return;
        }
        mountedRef.current = true;
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
