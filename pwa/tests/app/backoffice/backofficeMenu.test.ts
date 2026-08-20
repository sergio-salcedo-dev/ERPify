import { describe, expect, it } from "vitest";
import {
  accountMenuItem,
  backofficeMenuGroups,
  type NavItem,
  type NavSubItem,
} from "@/app/backoffice/_lib/backofficeMenu";
import { Routes } from "@/context/shared/routing/domain/Routes";

/**
 * Invariants of the navigation model itself, kept apart from the layout's behaviour so that a model
 * which stops declaring the sign-out intent fails here on an assertion — the layout's suite reads
 * that entry while building its fixtures, so there it would fail before a single test ran, and a
 * collection error says nothing about which invariant broke.
 */
const SIGN_OUT = "sign-out";

const EVERY_PARENT: readonly NavItem[] = [
  ...backofficeMenuGroups.flatMap((group) => group.items),
  accountMenuItem,
];

/**
 * The React key every sub-item render site derives, restated rather than imported: it is a
 * contract between the model and four hand-written JSX sites, so it has to fail here when the
 * model breaks it, not follow the component along.
 */
function keyOf(entry: NavSubItem): string {
  return entry.testId ?? entry.path;
}

const EVERY_SUB_ITEM: readonly NavSubItem[] = [
  ...backofficeMenuGroups.flatMap((group) => group.items).flatMap((item) => item.subItems ?? []),
  ...(accountMenuItem.subItems ?? []),
];

describe("the back-office navigation model", () => {
  it("declares the sign-out intent on exactly one entry", () => {
    // Scoped to every sub-item, not just the account group: `action` lives on NavSubItem, which
    // every group shares, so a second declaration elsewhere is legal in types, unreachable in the
    // UI — no other surface forwards it — and silently dead.
    const signOutEntries = EVERY_SUB_ITEM.filter((entry) => entry.action === SIGN_OUT);

    expect(signOutEntries.map((entry) => entry.name)).toEqual(["Logout"]);
  });

  it("gives each parent's sub-items a key of their own", () => {
    // Sub-items are keyed on identity, never on the label: a consumer that relabels an entry
    // while it works — which the sign-out entry does — would otherwise change the key, and React
    // would destroy the button the user just activated, dropping focus to <body> and moving the
    // new state onto a node no assistive technology is watching.
    //
    // That key falls back to `path`, and path reuse is INTENTIONAL in this model one level up
    // ("My profile" repeats its parent's own path on purpose), so "no two sub-items of one
    // parent share a path" is a real invariant the fallback rests on — and it was undocumented
    // and untested. The previous key (`name`) could not collide the same way: a duplicate label
    // is a visible bug.
    const collisions = EVERY_PARENT.flatMap((parent) => {
      const keys = (parent.subItems ?? []).map(keyOf);
      return keys
        .filter((key, index) => keys.indexOf(key) !== index)
        .map((key) => `${parent.name} → ${key}`);
    });

    expect(collisions).toEqual([]);
    // Non-vacuous: a model that stopped declaring sub-items entirely would satisfy the above.
    expect(EVERY_SUB_ITEM.length).toBeGreaterThan(0);
  });

  it("lands sign-out on the public landing, which the handler hard-codes", () => {
    // `path` is where sign-out goes, not what triggers it. The layout navigates to Routes.HOME
    // explicitly, so an entry pointing anywhere else would sign the user out and then leave them
    // somewhere the model never declared.
    const signOut = EVERY_SUB_ITEM.find((entry) => entry.action === SIGN_OUT);

    expect(signOut?.path).toBe(Routes.HOME);
  });
});
