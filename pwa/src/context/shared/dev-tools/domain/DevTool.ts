import type { LucideIcon } from "lucide-react";

/**
 * Single tool entry rendered by `<DevToolsMenu>`.
 *
 * Tools are grouped by category in {@link DevToolGroup}. Adding a new tool
 * is a matter of dropping an entry here — the menu page picks it up
 * automatically.
 */
export interface DevTool {
  /** Card title. */
  label: string;
  /** Internal URL the card links to. */
  url: string;
  /** Single-paragraph explanation rendered under the title. */
  description: string;
  /** Lucide icon for the card badge. */
  icon: LucideIcon;
}

/**
 * Named group of {@link DevTool} entries. The menu renders one section per
 * group so adjacent tools stay visually grouped.
 */
export interface DevToolGroup {
  /** Section heading. Keep it short — e.g. `Errors / QA`, `Observability`, … */
  label: string;
  tools: ReadonlyArray<DevTool>;
}
