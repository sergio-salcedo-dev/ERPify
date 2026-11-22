import { readFileSync } from "node:fs";
import { join } from "node:path";
import { expect, it, vi } from "vitest";
import { render, screen } from "@testing-library/react";
import { buttonVariants } from "@/components/ui/button-variants";
import { Input } from "@/components/ui/input";
import { BanksActiveFilters } from "@/app/backoffice/banks/_components/BanksActiveFilters";
import { BankAccountsActiveFilters } from "@/app/backoffice/bank-accounts/_components/BankAccountsActiveFilters";

// jsdom evaluates no media query: these pin the utilities, which compile to
// `@media (any-pointer: coarse)`. Desktop classes must stay untouched.

it.each([
  ["default", "h-8", "any-pointer-coarse:h-11"],
  ["xs", "h-6", "any-pointer-coarse:h-11"],
  ["sm", "h-7", "any-pointer-coarse:h-11"],
  ["lg", "h-9", "any-pointer-coarse:h-11"],
  ["icon", "size-8", "any-pointer-coarse:size-11"],
  ["icon-xs", "size-6", "any-pointer-coarse:size-11"],
  ["icon-sm", "size-7", "any-pointer-coarse:size-11"],
  ["icon-lg", "size-9", "any-pointer-coarse:size-11"],
] as const)(
  "Button size %s keeps %s and grows to 44px on coarse pointers",
  (size, base, coarse) => {
    const classes = buttonVariants({ size }).split(/\s+/);
    expect(classes).toContain(base);
    expect(classes).toContain(coarse);
  },
);

it("Input keeps h-8 and grows to 44px on coarse pointers", () => {
  render(<Input aria-label="x" />);
  expect(screen.getByLabelText("x")).toHaveClass("h-8", "any-pointer-coarse:h-11");
});

it.each([
  [
    "banks",
    <BanksActiveFilters
      key="b"
      chips={[{ key: "name", label: "Name: Acme" }]}
      onRemove={vi.fn()}
      onClearAll={vi.fn()}
    />,
    "banks-filters__chip-name",
  ],
  [
    "bank accounts",
    <BankAccountsActiveFilters
      key="a"
      chips={[{ key: "iban", label: "IBAN: ES" }] as never}
      onRemove={vi.fn()}
      onClearAll={vi.fn()}
    />,
    "bank-accounts-filters__chip-iban",
  ],
])("%s chip-remove is 24px (WCAG 2.5.8) and 44px on coarse pointers", (_n, ui, testId) => {
  render(ui);
  expect(screen.getByTestId(testId)).toHaveClass("size-6", "any-pointer-coarse:size-11");
  expect(screen.getByTestId(testId)).not.toHaveClass("size-4");
});

it.each([
  "src/app/backoffice/users/_components/UsersFilters.tsx",
  "src/app/backoffice/users/_components/UserEraseControl.tsx",
  "src/app/backoffice/users/_components/UserStatusControl.tsx",
  "src/app/backoffice/users/_components/UserRolesControl.tsx",
  "src/app/backoffice/banks/_components/BanksFilters.tsx",
  "src/app/backoffice/bank-accounts/_components/BankAccountsFilters.tsx",
  "src/app/backoffice/banks/[id]/accounts/_components/BankAccountForm.tsx",
  "src/app/backoffice/banks/[id]/accounts/_components/BankAccountStatusControl.tsx",
  "src/context/backoffice/audit/infrastructure/ui/AuditFilterBar.tsx",
])("%s pairs every control-height h-9 with any-pointer-coarse:h-11", (file) => {
  const source = readFileSync(join(process.cwd(), file), "utf8");
  const bare = source.match(/\bh-9\b(?! any-pointer-coarse:h-11)/g) ?? [];
  expect(bare).toEqual([]);
  expect(source).toContain("h-9 any-pointer-coarse:h-11");
});
