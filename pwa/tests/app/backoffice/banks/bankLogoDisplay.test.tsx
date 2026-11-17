import { describe, expect, it, vi } from "vitest";
import { render, screen } from "@testing-library/react";
import { BanksCards } from "@/app/backoffice/banks/_components/BanksCards";
import { BanksStackedList } from "@/app/backoffice/banks/_components/BanksStackedList";
import { BanksTable } from "@/app/backoffice/banks/_components/BanksTable";
import { Bank } from "@/context/backoffice/bank/domain/Bank";
import { ACME, BETA } from "./_fixtures";

/**
 * Every list surface shows the bank's logo beside its name, keyed by the row id, and falls back to
 * the placeholder for a bank without one. The image is decorative (`alt=""`): the name is next to it.
 */

vi.mock("next/navigation", async () => (await import("./_mocks")).routerMock());

const LOGO = "0190a1b2-c3d4-7e5f-8a9b-0c1d2e3f4a01";
const WITH_LOGO = Bank.fromPrimitives({ ...ACME, logoImageId: LOGO });
const WITHOUT_LOGO = BETA;
const noop = () => undefined;

function expectLogoOn(surface: string): void {
  const image = screen.getByTestId(`${surface}__logo-${WITH_LOGO.id}__image`);
  expect(image).toHaveAttribute("src", expect.stringContaining(LOGO));
  expect(image).toHaveAttribute("alt", "");
  expect(screen.queryByTestId(`${surface}__logo-${WITHOUT_LOGO.id}__image`)).toBeNull();
  expect(
    screen.getByTestId(`${surface}__logo-${WITHOUT_LOGO.id}__placeholder`),
  ).toBeInTheDocument();
}

describe("bank logo on the list surfaces", () => {
  it("shows it in the table name cell", () => {
    render(
      <BanksTable
        banks={[WITH_LOGO, WITHOUT_LOGO]}
        visible={["status", "accounts"]}
        onBankDeleteFailed={noop}
      />,
    );
    expectLogoOn("banks-table");
  });

  it("shows it on the card", () => {
    render(<BanksCards banks={[WITH_LOGO, WITHOUT_LOGO]} onBankDeleteFailed={noop} />);
    expectLogoOn("banks-cards");
  });

  it("shows it on the stacked row", () => {
    render(<BanksStackedList banks={[WITH_LOGO, WITHOUT_LOGO]} onBankDeleteFailed={noop} />);
    expectLogoOn("banks-stacked");
  });
});
