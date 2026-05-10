import { LayoutGrid } from "lucide-react";
import type { DevToolGroup } from "@/context/shared/dev-tools/domain/DevTool";
import { Routes } from "@/context/shared/domain/types/routes";

/**
 * Authoritative registry of every dev / QA tool the PWA ships. Imported by
 * `<DevToolsMenu>` to render the page; new tools land by adding an entry
 * here without touching the rendering code.
 *
 * The registry is gated to `NODE_ENV !== production` at the page level —
 * see `app/dev-tools/page.tsx` and {@link isDevToolsAvailable}.
 */
export const DEV_TOOL_GROUPS: ReadonlyArray<DevToolGroup> = [
  {
    label: "Errors / QA",
    tools: [
      {
        label: "Error Gallery",
        url: `${Routes.DEV_TOOLS}/error-gallery`,
        description:
          "Visual inventory of every PWA error surface (404, 401, 403, 500, 503, 429, offline) plus inline `<ProblemDisplay>` fixtures for 4xx, 422 with violations, and 5xx with the dev-only `debug` block.",
        icon: LayoutGrid,
      },
    ],
  },
];
