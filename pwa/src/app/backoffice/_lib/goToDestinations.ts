import type { NavGroup, NavSubItem } from "./backofficeMenu";
import { isPlannedPath } from "./plannedModules";

/** One place the "Go to" palette can open. */
export interface GoToDestination {
  name: string;
  path: string;
  /** Where the entry sits in the sidebar, shown beside it and searched with it. */
  section: string;
  isPlanned: boolean;
}

/**
 * Every page the session's navigation offers, flattened in sidebar order. Built from the menu model
 * the sidebar renders AFTER its permission filter, so the palette can never offer a door the sidebar
 * hides. Leaves stand for their parent (which repeats one of their paths), the first entry wins a
 * shared path, and the sign-out entry is left out: it is an action, not a page.
 */
export function goToDestinations(
  groups: readonly NavGroup[],
  accountEntries: readonly NavSubItem[],
  accountSection: string,
): GoToDestination[] {
  const destinations = new Map<string, GoToDestination>();
  const add = (name: string, path: string, section: string): void => {
    if (destinations.has(path)) return;
    destinations.set(path, { name, path, section, isPlanned: isPlannedPath(path) });
  };

  for (const group of groups) {
    for (const item of group.items) {
      if (item.subItems && item.subItems.length > 0) {
        for (const sub of item.subItems) {
          if (sub.action === undefined) add(sub.name, sub.path, `${group.label} › ${item.name}`);
        }
      } else {
        add(item.name, item.path, group.label);
      }
    }
  }
  for (const entry of accountEntries) {
    if (entry.action === undefined) add(entry.name, entry.path, accountSection);
  }

  return [...destinations.values()];
}

/**
 * The destinations matching `query`: every whitespace-separated term must appear, case-insensitively,
 * in the name or the section, so "fin cash" finds Cash Flow under Finance. Live pages come before
 * planned ones, and a name starting with the query before one merely containing it; otherwise the
 * sidebar's order is kept, which is the order the operator already knows.
 */
export function filterDestinations(
  query: string,
  destinations: readonly GoToDestination[],
): GoToDestination[] {
  const terms = query.trim().toLowerCase().split(/\s+/).filter(Boolean);
  const head = terms.join(" ");
  const matches = destinations.filter((destination) => {
    const haystack = `${destination.name} ${destination.section}`.toLowerCase();
    return terms.every((term) => haystack.includes(term));
  });

  const rank = (destination: GoToDestination): number =>
    (destination.isPlanned ? 2 : 0) +
    (head !== "" && !destination.name.toLowerCase().startsWith(head) ? 1 : 0);

  return matches
    .map((destination, index) => ({ destination, index }))
    .sort((a, b) => rank(a.destination) - rank(b.destination) || a.index - b.index)
    .map(({ destination }) => destination);
}
