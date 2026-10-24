import { describe, expect, it } from "vitest";
import {
  activeEntryPath,
  isItemActive,
  isItemPlanned,
  matchesPath,
} from "@/app/backoffice/_lib/menuState";
import { backofficeMenuGroups, type NavItem } from "@/app/backoffice/_lib/backofficeMenu";
import { Routes } from "@/context/shared/routing/domain/Routes";

const BANKS = `${Routes.BACKOFFICE}/banks`;
const BANK_ACCOUNTS = `${Routes.BACKOFFICE}/bank-accounts`;
const COMPANIES = `${Routes.BACKOFFICE}/companies`;
const EMPLOYEES = `${Routes.BACKOFFICE}/companies/employees`;

function itemNamed(name: string): NavItem {
  const item = backofficeMenuGroups.flatMap((group) => group.items).find((i) => i.name === name);
  if (item === undefined) throw new Error(`The navigation model no longer declares "${name}".`);
  return item;
}

describe("matchesPath", () => {
  it("keeps a record's detail and edit pages under their list's entry", () => {
    expect(matchesPath(BANKS, BANKS)).toBe(true);
    expect(matchesPath(`${BANKS}/0190aaaa-bbbb-7ccc-8ddd-0e1f2a3b4c5d`, BANKS)).toBe(true);
    expect(matchesPath(`${BANKS}/0190aaaa-bbbb-7ccc-8ddd-0e1f2a3b4c5d/edit`, BANKS)).toBe(true);
  });

  it("matches on a segment boundary, so one entry never claims a sibling sharing its prefix", () => {
    expect(matchesPath(BANK_ACCOUNTS, BANKS)).toBe(false);
  });

  it("matches the back-office root exactly, since as a prefix it would match every route", () => {
    expect(matchesPath(Routes.BACKOFFICE, Routes.BACKOFFICE)).toBe(true);
    expect(matchesPath(BANKS, Routes.BACKOFFICE)).toBe(false);
  });
});

describe("activeEntryPath", () => {
  it("lights the most specific sibling when several match by prefix", () => {
    expect(activeEntryPath(EMPLOYEES, [COMPANIES, EMPLOYEES])).toBe(EMPLOYEES);
    expect(activeEntryPath(COMPANIES, [COMPANIES, EMPLOYEES])).toBe(COMPANIES);
  });

  it("lights nothing when no sibling matches", () => {
    expect(activeEntryPath(BANKS, [COMPANIES, EMPLOYEES])).toBeUndefined();
  });
});

describe("isItemActive", () => {
  it("keeps Treasury lit on a bank's detail page", () => {
    expect(
      isItemActive(`${BANKS}/0190aaaa-bbbb-7ccc-8ddd-0e1f2a3b4c5d`, itemNamed("Treasury")),
    ).toBe(true);
  });

  it("does not light the Dashboard from a nested route", () => {
    expect(isItemActive(BANKS, itemNamed("Dashboard"))).toBe(false);
    expect(isItemActive(Routes.BACKOFFICE, itemNamed("Dashboard"))).toBe(true);
  });
});

describe("isItemPlanned", () => {
  it("marks a parent planned only when every leaf behind it is", () => {
    expect(isItemPlanned(itemNamed("Sales"))).toBe(true);
    // Banks and Bank Accounts are live; Transactions and Cash Flow are not.
    expect(isItemPlanned(itemNamed("Treasury"))).toBe(false);
  });

  it("never marks a live module planned", () => {
    expect(isItemPlanned(itemNamed("Dashboard"))).toBe(false);
    expect(isItemPlanned(itemNamed("Configuration"))).toBe(false);
  });
});
