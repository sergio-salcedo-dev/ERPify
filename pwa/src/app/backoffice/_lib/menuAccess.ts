import { authorize } from "@/context/shared/access/domain/authorize";
import type { Session } from "@/context/shared/access/domain/Session";
import type { NavGroup, NavItem, NavPermission, NavSubItem } from "./backofficeMenu";

/**
 * Whether a session may be offered an entry. An entry declaring no permission is offered to every
 * session; a null session is offered none of the gated ones (the back-office chrome only renders
 * behind `RequireAuth`, so this arm is reached by nothing but a defensive read).
 *
 * The pure `authorize()` rather than `useCan`, because this runs once per entry inside a loop and a
 * hook cannot.
 */
function allows(session: Session | null, entry: NavPermission): boolean {
  const required = entry.permission;
  return required === undefined || (session !== null && authorize(session, required));
}

/**
 * One item, reduced to what this session may be offered — or `null` when nothing of it survives.
 *
 * A parent whose sub-items are all filtered away is dropped rather than left standing: every parent
 * here repeats one of its own leaves as its `path` (the ERP convention), so an empty one is a door
 * onto a page the session cannot open. That same convention is why a surviving parent may still need
 * re-pointing — `Configuration` points at `/users`, which is the very leaf a non-admin loses — and
 * leaving it would keep the dead end on the two surfaces where a parent click navigates (the compact
 * sidebar and the mobile drawer). The re-point fires only when the parent's own destination was one
 * of the REMOVED leaves, so a parent pointing somewhere of its own is never rewritten.
 */
function permittedItem(session: Session | null, item: NavItem): NavItem | null {
  if (!allows(session, item)) return null;
  if (item.subItems === undefined) return item;

  const subItems: NavSubItem[] = item.subItems.filter((sub) => allows(session, sub));
  if (subItems.length === 0) return null;
  if (subItems.length === item.subItems.length) return item;

  const destinationSurvives = subItems.some((sub) => sub.path === item.path);
  const destinationWasALeaf = item.subItems.some((sub) => sub.path === item.path);
  const path = !destinationSurvives && destinationWasALeaf ? subItems[0].path : item.path;

  return { ...item, path, subItems };
}

/**
 * The account entries this session may be offered. The avatar menu renders from `accountMenuItem`
 * rather than from the groups, so it does not pass through {@link permittedMenuGroups} — and
 * `NavPermission` is declared on `NavSubItem` as well as `NavItem`, so a `permission` on "Active
 * sessions" or "Notifications" would be a field the type advertises and no account surface honours.
 * Filtering here is what makes the declaration mean the same thing on every menu — the caller filters
 * once into an item and hands THAT to all three account surfaces, never this list to one of them.
 *
 * Only the ENTRIES are filtered, never the account item itself: it is the chrome's own affordance
 * and is always offered, so a `permission` on the parent would have nowhere to be honoured. That is
 * not left to a reader to notice — `backOfficeMenuPermissions.test.tsx` refuses one at the parent.
 */
export function permittedAccountEntries(
  session: Session | null,
  entries: readonly NavSubItem[],
): NavSubItem[] {
  return entries.filter((entry) => allows(session, entry));
}

/**
 * The navigation model as this session may be offered it: every entry whose declared permission the
 * session does not hold is gone, and a group left with no items disappears with them.
 *
 * Applied once, at the single place both sidebars read their model from, because the desktop and
 * mobile surfaces render the same groups through different JSX — filtering at either render site
 * would leave the other painting doors the role cannot open. It is navigation UX and never a control:
 * the page behind each route enforces its own gate, and must, because the URL can be typed.
 */
export function permittedMenuGroups(
  session: Session | null,
  groups: readonly NavGroup[],
): NavGroup[] {
  return groups
    .map((group) => ({
      ...group,
      items: group.items
        .map((item) => permittedItem(session, item))
        .filter((item): item is NavItem => item !== null),
    }))
    .filter((group) => group.items.length > 0);
}
