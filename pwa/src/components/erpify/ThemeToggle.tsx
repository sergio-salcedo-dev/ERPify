"use client";

import { useSyncExternalStore } from "react";
import { useTheme } from "next-themes";
import { Sun, Moon, Monitor, type LucideIcon } from "lucide-react";
import { Button } from "@/components/ui/button";
import { Theme } from "@/context/shared/theme/domain/Theme";
import { cn } from "@/components/cn";

export interface ThemeToggleProps {
  /** Forwarded to the rendered button as `data-testid`. */
  testId?: string;
  className?: string;
}

/**
 * Cycles the active theme light → dark → system → light via `next-themes`.
 *
 * The icon reflects the CURRENT chosen theme; the `title` / `aria-label`
 * describe the NEXT action so assistive tech announces what a click will do.
 * A mounted guard renders a neutral placeholder until the first client effect
 * runs, so the server markup (theme unknown) never mismatches the hydrated
 * client (theme resolved) — without it React would warn and flash the wrong
 * icon.
 */

const ORDER = [Theme.LIGHT, Theme.DARK, Theme.SYSTEM] as const;

const NEXT_THEME: Record<Theme, Theme> = {
  [Theme.LIGHT]: Theme.DARK,
  [Theme.DARK]: Theme.SYSTEM,
  [Theme.SYSTEM]: Theme.LIGHT,
};

const ICON_FOR_THEME: Record<Theme, LucideIcon> = {
  [Theme.LIGHT]: Sun,
  [Theme.DARK]: Moon,
  [Theme.SYSTEM]: Monitor,
};

const NEXT_ACTION_LABEL: Record<Theme, string> = {
  [Theme.LIGHT]: "Switch to dark theme",
  [Theme.DARK]: "Switch to system theme",
  [Theme.SYSTEM]: "Switch to light theme",
};

function isTheme(value: string | undefined): value is Theme {
  return ORDER.includes(value as Theme);
}

const noop = () => () => undefined;

/**
 * `true` once hydrated on the client, `false` during SSR. Uses
 * `useSyncExternalStore`'s server snapshot to distinguish the two without a
 * synchronous `setState` inside an effect (which the React Compiler lint rules
 * forbid). The store never changes, so no subscription work is needed.
 */
function useHydrated(): boolean {
  return useSyncExternalStore(
    noop,
    () => true,
    () => false,
  );
}

export function ThemeToggle({ testId, className }: Readonly<ThemeToggleProps>) {
  const { theme, setTheme } = useTheme();
  const mounted = useHydrated();

  const current: Theme = isTheme(theme) ? theme : Theme.SYSTEM;
  // Until mounted the chosen theme is unknown on the client, so render a
  // stable, theme-neutral placeholder that matches the server output.
  const Icon = mounted ? ICON_FOR_THEME[current] : Monitor;
  const label = mounted ? NEXT_ACTION_LABEL[current] : "Toggle theme";

  return (
    <Button
      type="button"
      variant="ghost"
      size="icon-sm"
      aria-label={label}
      title={label}
      data-testid={testId}
      // Updater form: next-themes hands the updater the theme active at
      // dispatch, so an unknown stored value recovers to a valid one on click.
      onClick={() => setTheme((active) => NEXT_THEME[isTheme(active) ? active : Theme.SYSTEM])}
      className={cn("theme-toggle", className)}
    >
      <Icon className="size-4" aria-hidden />
      <span className="sr-only">{label}</span>
    </Button>
  );
}
