import { describe, it, expect, vi } from "vitest";
import { render, screen } from "@testing-library/react";

// The form is a Client Component reaching for the router and the DI container; this route's
// contract is the id it hands down, so the form is stubbed to observe exactly that.
vi.mock("@/app/backoffice/banks/[id]/accounts/_components/BankAccountForm", () => ({
  BankAccountForm: ({ bankId }: Readonly<{ bankId: string }>) => (
    <p data-testid="bank-account-new-test__form">{bankId}</p>
  ),
}));

import NewBankAccountPage, { metadata } from "@/app/backoffice/banks/[id]/accounts/new/page";

describe("New bank account page", () => {
  it("declares the new account page title", () => {
    expect(metadata.title).toBe("New account");
  });

  it("reads the bank id from `params` rather than from a client hook", async () => {
    // The route is a Server Component again, which is what retires the segment layout that
    // carried its title. Awaiting the component is what a Server Component render is.
    render(await NewBankAccountPage({ params: Promise.resolve({ id: "bank-7" }) }));

    expect(screen.getByTestId("bank-account-new-test__form")).toHaveTextContent("bank-7");
    expect(screen.getByTestId("bank-accounts-new__back-link")).toHaveAttribute(
      "href",
      "/backoffice/banks/bank-7/accounts",
    );
  });
});
