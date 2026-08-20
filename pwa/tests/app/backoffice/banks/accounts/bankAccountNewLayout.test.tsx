import { describe, it, expect } from "vitest";
import NewBankAccountLayout, { metadata } from "@/app/backoffice/banks/[id]/accounts/new/layout";

describe("New bank account layout metadata", () => {
  it("declares the new account page title", () => {
    expect(metadata.title).toBe("New account");
  });

  it("passes its children through unchanged", () => {
    const children: React.ReactNode = "child";

    expect(NewBankAccountLayout({ children })).toBe(children);
  });
});
