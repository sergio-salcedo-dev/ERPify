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
  const candidates: ReadonlyArray<Omit<GoToDestination, "isPlanned">> = [
    ...groups.flatMap(groupEntries),
    ...accountEntries.filter(isPage).map((entry) => ({
      name: entry.name,
      path: entry.path,
      section: accountSection,
    })),
  ];

  const destinations = new Map<string, GoToDestination>();
  for (const candidate of candidates) {
    if (!destinations.has(candidate.path)) {
      destinations.set(candidate.path, { ...candidate, isPlanned: isPlannedPath(candidate.path) });
    }
  }
  return [...destinations.values()];
}

/** A leaf that navigates, as opposed to one that triggers an action such as signing out. */
function isPage(entry: NavSubItem): boolean {
  return entry.action === undefined;
}

/** One group's entries in sidebar order: a parent's leaves when it has any, otherwise itself. */
function groupEntries(group: NavGroup): Array<Omit<GoToDestination, "isPlanned">> {
  return group.items.flatMap((item) => {
    if (item.subItems === undefined || item.subItems.length === 0) {
      return [{ name: item.name, path: item.path, section: group.label }];
    }
    const section = `${group.label} › ${item.name}`;
    return item.subItems.filter(isPage).map((sub) => ({ name: sub.name, path: sub.path, section }));
  });
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
