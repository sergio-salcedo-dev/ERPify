import { beforeEach, describe, expect, it, vi } from "vitest";
import { fireEvent, render, screen, waitFor } from "@testing-library/react";
import { BankForm } from "@/app/backoffice/banks/_components/BankForm";
import { Bank } from "@/context/backoffice/bank/domain/Bank";
import { PersistenceAction } from "@/context/shared/domain/types/status";
import { toastNotifier } from "@/context/shared/infrastructure/Notification/Toast";

const CREATED = Bank.fromPrimitives({
  id: "11111111-1111-4111-8111-111111111111",
  name: "Acme Savings",
  shortName: "ACME",
  createdAt: "2026-01-01T10:00:00Z",
  updatedAt: "2026-01-01T10:00:00Z",
});

const mocks = await vi.hoisted(async () => (await import("./_mocks")).bankFormMocks());
vi.mock("next/navigation", () => mocks.navigation);
vi.mock("@/context/shared/infrastructure/DependencyInjection/Container", () => mocks.container);
vi.mock("@/context/shared/infrastructure/Notification/Toast", () => mocks.toast);

const { push, createRun } = mocks;

describe("BankForm — feedback", () => {
  beforeEach(() => {
    vi.clearAllMocks();
  });

  it("shows a success toast with the bank name after create", async () => {
    createRun.mockResolvedValue(CREATED);
    render(<BankForm mode={PersistenceAction.CREATING} />);

    fireEvent.change(screen.getByTestId("bank-form__name"), { target: { value: "Acme Savings" } });
    fireEvent.change(screen.getByTestId("bank-form__short-name"), { target: { value: "ACME" } });
    fireEvent.submit(screen.getByTestId("bank-form"));

    await waitFor(() => {
      expect(toastNotifier.success).toHaveBeenCalledWith("Bank created", {
        description: "Acme Savings",
      });
    });
    expect(push).toHaveBeenCalled();
  });

  it("shows a 'Saving…' spinner while the submit is in flight", async () => {
    let resolveCreate: (b: Bank) => void = () => {};
    createRun.mockReturnValue(
      new Promise<Bank>((resolve) => {
        resolveCreate = resolve;
      }),
    );
    render(<BankForm mode={PersistenceAction.CREATING} />);

    fireEvent.change(screen.getByTestId("bank-form__name"), { target: { value: "Acme Savings" } });
    fireEvent.change(screen.getByTestId("bank-form__short-name"), { target: { value: "ACME" } });
    fireEvent.submit(screen.getByTestId("bank-form"));

    expect(await screen.findByTestId("bank-form__submit-spinner")).toBeInTheDocument();
    expect(screen.getByTestId("bank-form__submit")).toBeDisabled();

    resolveCreate(CREATED);
    await waitFor(() => {
      expect(screen.queryByTestId("bank-form__submit-spinner")).not.toBeInTheDocument();
    });
  });
});
