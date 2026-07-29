import { describe, expect, it, vi } from "vitest";
import { fireEvent, render, screen } from "@testing-library/react";
import { BankRowActions } from "@/app/backoffice/banks/_components/BankRowActions";
import { openRowDeleteItem } from "./_interactions";

vi.mock("next/navigation", () => ({
  useRouter: () => ({ push: vi.fn(), refresh: vi.fn(), back: vi.fn() }),
}));

const ID = "11111111-1111-4111-8111-111111111111";

describe("BankRowActions", () => {
  it("renders copy and edit as direct controls plus an overflow trigger", () => {
    render(
      <BankRowActions
        id={ID}
        name="Acme Savings"
        surface="table"
        accountCount={0}
        onBankDeleteFailed={vi.fn()}
      />,
    );
    expect(screen.getByTestId(`banks-table__copy-${ID}`)).toBeInTheDocument();
    expect(screen.getByTestId(`banks-table__edit-${ID}`)).toBeInTheDocument();
    expect(screen.getByTestId(`banks-table__actions-${ID}`)).toBeInTheDocument();
  });

  it("points Edit at the bank edit route", () => {
    render(
      <BankRowActions
        id={ID}
        name="Acme Savings"
        surface="cards"
        accountCount={0}
        onBankDeleteFailed={vi.fn()}
      />,
    );
    expect(screen.getByTestId(`banks-cards__edit-${ID}`)).toHaveAttribute(
      "href",
      `/backoffice/banks/${ID}/edit`,
    );
  });

  it("keeps Delete behind the overflow menu until it is opened", async () => {
    render(
      <BankRowActions
        id={ID}
        name="Acme Savings"
        surface="cards"
        accountCount={0}
        onBankDeleteFailed={vi.fn()}
      />,
    );
    expect(screen.queryByTestId(`banks-cards__delete-${ID}`)).toBeNull();

    expect(await openRowDeleteItem("banks-cards", ID)).toBeInTheDocument();
  });

  it("opens the confirmation dialog from the Delete menu item", async () => {
    render(
      <BankRowActions
        id={ID}
        name="Acme Savings"
        surface="cards"
        accountCount={0}
        onBankDeleteFailed={vi.fn()}
      />,
    );

    fireEvent.click(await openRowDeleteItem("banks-cards", ID));

    expect(await screen.findByTestId("banks-detail__delete-dialog")).toBeInTheDocument();
  });

  it("opens the in-use guard (not the destructive confirm) when the bank has accounts", async () => {
    render(
      <BankRowActions
        id={ID}
        name="Acme Savings"
        surface="cards"
        accountCount={2}
        onBankDeleteFailed={vi.fn()}
      />,
    );

    fireEvent.click(await openRowDeleteItem("banks-cards", ID));

    expect(await screen.findByTestId("banks-detail__delete-guard-view-accounts")).toHaveAttribute(
      "href",
      `/backoffice/banks/${ID}/accounts`,
    );
    expect(screen.queryByTestId("banks-detail__delete-confirm")).toBeNull();
  });
});
