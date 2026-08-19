import { describe, expect, it } from "vitest";
import {
  accountMenuItem,
  backofficeMenuGroups,
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

  it("lands sign-out on the public landing, which the handler hard-codes", () => {
    // `path` is where sign-out goes, not what triggers it. The layout navigates to Routes.HOME
    // explicitly, so an entry pointing anywhere else would sign the user out and then leave them
    // somewhere the model never declared.
    const signOut = EVERY_SUB_ITEM.find((entry) => entry.action === SIGN_OUT);

    expect(signOut?.path).toBe(Routes.HOME);
  });
});
