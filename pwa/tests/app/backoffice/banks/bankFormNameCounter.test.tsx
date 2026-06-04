import { describe, expect, it, vi } from "vitest";
import { fireEvent, render, screen } from "@testing-library/react";
import { BankForm } from "@/app/backoffice/banks/_components/BankForm";
import { PersistenceAction } from "@/context/shared/domain/types/status";

vi.mock("next/navigation", () => ({
  useRouter: () => ({ push: vi.fn(), refresh: vi.fn(), back: vi.fn() }),
}));

describe("BankForm — name length counter", () => {
  it("stays hidden below 80% of the limit and appears near it", () => {
    render(<BankForm mode={PersistenceAction.CREATING} />);
    const field = screen.getByTestId("bank-form__name");

    fireEvent.change(field, { target: { value: "B".repeat(100) } });
    expect(screen.queryByTestId("bank-form__name-counter")).toBeNull();

    fireEvent.change(field, { target: { value: "B".repeat(204) } });
    expect(screen.getByTestId("bank-form__name-counter")).toHaveTextContent("204/255");

    fireEvent.change(field, { target: { value: "B".repeat(255) } });
    expect(screen.getByTestId("bank-form__name-counter")).toHaveTextContent("255/255");
  });

  it("renders the name field as an auto-growing textarea, readable in full", () => {
    render(
      <BankForm
        mode={PersistenceAction.UPDATING}
        initial={{ id: "55555555-5555-4555-8555-555555555555", name: "N".repeat(255), shortName: "NB" }}
      />,
    );
    const field = screen.getByTestId("bank-form__name");
    expect(field.tagName).toBe("TEXTAREA");
    expect((field as HTMLTextAreaElement).value).toHaveLength(255);
    expect(field).not.toHaveAttribute("maxlength");
  });
});
