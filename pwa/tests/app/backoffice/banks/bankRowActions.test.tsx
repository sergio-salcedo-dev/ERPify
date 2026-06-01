import { describe, expect, it, vi } from "vitest";
import { fireEvent, render, screen } from "@testing-library/react";
import { BankRowActions } from "@/app/backoffice/banks/_components/BankRowActions";

vi.mock("next/navigation", () => ({
  useRouter: () => ({ push: vi.fn(), refresh: vi.fn(), back: vi.fn() }),
}));

const ID = "11111111-1111-4111-8111-111111111111";

describe("BankRowActions", () => {
  it("renders copy and edit as direct controls plus an overflow trigger", () => {
    render(<BankRowActions id={ID} name="Acme Savings" surface="table" />);
    expect(screen.getByTestId(`banks-table__copy-${ID}`)).toBeInTheDocument();
    expect(screen.getByTestId(`banks-table__edit-${ID}`)).toBeInTheDocument();
    expect(screen.getByTestId(`banks-table__actions-${ID}`)).toBeInTheDocument();
  });

  it("points Edit at the bank edit route", () => {
    render(<BankRowActions id={ID} name="Acme Savings" surface="cards" />);
    expect(screen.getByTestId(`banks-cards__edit-${ID}`)).toHaveAttribute(
      "href",
      `/backoffice/banks/${ID}/edit`,
    );
  });

  it("keeps Delete behind the overflow menu until it is opened", async () => {
    render(<BankRowActions id={ID} name="Acme Savings" surface="cards" />);
    expect(screen.queryByTestId(`banks-cards__delete-${ID}`)).toBeNull();

    fireEvent.click(screen.getByTestId(`banks-cards__actions-${ID}`));

    expect(await screen.findByTestId(`banks-cards__delete-${ID}`)).toBeInTheDocument();
  });

  it("opens the confirmation dialog from the Delete menu item", async () => {
    render(<BankRowActions id={ID} name="Acme Savings" surface="cards" />);

    fireEvent.click(screen.getByTestId(`banks-cards__actions-${ID}`));
    fireEvent.click(await screen.findByTestId(`banks-cards__delete-${ID}`));

    expect(await screen.findByTestId("banks-detail__delete-dialog")).toBeInTheDocument();
  });
});
