import { describe, expect, it } from "vitest";
import {
  filterDestinations,
  goToDestinations,
  type GoToDestination,
} from "@/app/backoffice/_lib/goToDestinations";
import { accountMenuItem, backofficeMenuGroups } from "@/app/backoffice/_lib/backofficeMenu";
import { Routes } from "@/context/shared/routing/domain/Routes";

const ALL = goToDestinations(backofficeMenuGroups, accountMenuItem.subItems ?? [], "Account");

function names(destinations: readonly GoToDestination[]): string[] {
  return destinations.map((destination) => destination.name);
}

describe("goToDestinations", () => {
  it("offers each page once, by its leaf rather than its parent", () => {
    const paths = ALL.map((destination) => destination.path);
    expect(new Set(paths).size).toBe(paths.length);
    // Treasury repeats the Banks path; the leaf is what the operator recognises.
    expect(names(ALL)).toContain("Banks");
    expect(names(ALL)).not.toContain("Treasury");
  });

  it("leaves the sign-out entry out, since it is an action rather than a page", () => {
    expect(names(ALL)).not.toContain("Logout");
    expect(ALL.map((destination) => destination.path)).not.toContain(Routes.HOME);
  });

  it("locates each leaf by its group and parent", () => {
    expect(ALL.find((destination) => destination.name === "Banks")?.section).toBe(
      "Finance › Treasury",
    );
    expect(ALL.find((destination) => destination.name === "My profile")?.section).toBe("Account");
  });
});

describe("filterDestinations", () => {
  it("requires every term, across the name and the section", () => {
    expect(names(filterDestinations("treasury cash", ALL))).toEqual(["Cash Flow"]);
  });

  it("ignores case and surrounding whitespace", () => {
    expect(names(filterDestinations("  BANKS ", ALL))).toEqual(["Banks"]);
  });

  it("ranks live pages before planned ones, and name prefixes before mere containment", () => {
    const result = filterDestinations("bank", ALL);
    expect(names(result).slice(0, 2)).toEqual(["Banks", "Bank Accounts"]);
    expect(result.every((destination, index) => index < 2 || destination.isPlanned)).toBe(true);
  });

  it("keeps the sidebar's order for an empty query", () => {
    expect(filterDestinations("", ALL).filter((d) => !d.isPlanned)[0]?.name).toBe("Dashboard");
  });

  it("returns nothing when no page matches", () => {
    expect(filterDestinations("zzz-nothing", ALL)).toEqual([]);
  });
});
