import { Routes } from "@/context/shared/routing/domain/Routes";
import { isPlannedPath } from "./plannedModules";
import type { NavItem } from "./backofficeMenu";

/**
 * Whether the current route is `path` or lives under it, so a record's detail or edit page keeps
 * its list's entry lit. The back-office root is the one exact match: as a prefix of every route it
 * would light the Dashboard everywhere. The `/` boundary keeps `/banks` from claiming
 * `/bank-accounts`.
 */
export function matchesPath(pathname: string, path: string): boolean {
  if (path === Routes.BACKOFFICE || path === Routes.HOME) return pathname === path;
  return pathname === path || pathname.startsWith(`${path}/`);
}

/**
 * The one entry among `paths` the current route belongs to: the longest that matches, so on
 * `/companies/employees` the Employees leaf is lit and its sibling Companies — which also matches
 * by prefix — is not. `undefined` when none matches.
 */
export function activeEntryPath(pathname: string, paths: readonly string[]): string | undefined {
  return paths
    .filter((path) => matchesPath(pathname, path))
    .reduce<string | undefined>(
      (longest, path) => (longest === undefined || path.length > longest.length ? path : longest),
      undefined,
    );
}

/** Whether a top-level entry, or any of its leaves, holds the current route. */
export function isItemActive(pathname: string, item: NavItem): boolean {
  if (item.subItems && item.subItems.length > 0) {
    return (
      activeEntryPath(
        pathname,
        item.subItems.map((sub) => sub.path),
      ) !== undefined
    );
  }
  return matchesPath(pathname, item.path);
}

/**
 * Whether an entry leads only to modules that are not built yet. A parent is planned when every
 * leaf it opens is — Treasury, with live Banks beside planned Cash Flow, is not.
 */
export function isItemPlanned(item: NavItem): boolean {
  if (item.subItems && item.subItems.length > 0) {
    return item.subItems.every((sub) => isPlannedPath(sub.path));
  }
  return isPlannedPath(item.path);
}
