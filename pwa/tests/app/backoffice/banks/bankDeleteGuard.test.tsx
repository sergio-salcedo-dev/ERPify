import { beforeEach, describe, expect, it, vi } from "vitest";
import { fireEvent, render, screen } from "@testing-library/react";
import { DeleteBankButton } from "@/app/backoffice/banks/_components/DeleteBankButton";

vi.mock("next/navigation", () => ({
  useRouter: () => ({ push: vi.fn(), refresh: vi.fn(), back: vi.fn() }),
}));

const deleteRun = vi.fn();
vi.mock("@/context/shared/dependency-injection/infrastructure/Container", () => ({
  container: {
    get: (token: string) => {
      if (token === "BackOfficeDeleteBank") return { run: deleteRun };
      throw new Error(`Unexpected DI token ${token}`);
    },
  },
}));

vi.mock("@/context/shared/notification/infrastructure/Toast", () => ({
  toastNotifier: {
    success: vi.fn(),
    error: vi.fn(),
    info: vi.fn(),
    warning: vi.fn(),
    dismissAll: vi.fn(),
  },
}));

const ID = "11111111-1111-4111-8111-111111111111";

describe("DeleteBankButton — optimistic delete-guard (accountCount > 0)", () => {
  beforeEach(() => {
    vi.clearAllMocks();
  });

  it("intercepts the delete and never emits DELETE when the bank has accounts", async () => {
    render(<DeleteBankButton id={ID} name="Acme Savings" accountCount={3} onError={vi.fn()} />);

    fireEvent.click(screen.getByTestId("banks-detail__delete-button"));

    // The neutral guard replaces the destructive confirmation: no DELETE path.
    const message = await screen.findByTestId("banks-detail__delete-guard-message");
    expect(message).toHaveTextContent("3 associated accounts");
    expect(screen.queryByTestId("banks-detail__delete-confirm")).toBeNull();
    expect(deleteRun).not.toHaveBeenCalled();
  });

  it("offers a View accounts recovery pointing at the accounts surface", async () => {
    render(<DeleteBankButton id={ID} name="Acme Savings" accountCount={3} onError={vi.fn()} />);

    fireEvent.click(screen.getByTestId("banks-detail__delete-button"));

    const viewAccounts = await screen.findByTestId("banks-detail__delete-guard-view-accounts");
    expect(viewAccounts).toHaveAttribute("href", `/backoffice/banks/${ID}/accounts`);
  });

  it("uses singular noun agreement for a single account", async () => {
    render(<DeleteBankButton id={ID} name="Acme Savings" accountCount={1} onError={vi.fn()} />);

    fireEvent.click(screen.getByTestId("banks-detail__delete-button"));

    expect(await screen.findByTestId("banks-detail__delete-guard-message")).toHaveTextContent(
      "1 associated account",
    );
  });

  it("keeps the destructive confirmation flow when the bank has no accounts", async () => {
    deleteRun.mockResolvedValue(undefined);
    const onDeleted = vi.fn();
    render(
      <DeleteBankButton
        id={ID}
        name="Acme Savings"
        accountCount={0}
        onDeleted={onDeleted}
        onError={vi.fn()}
      />,
    );

    fireEvent.click(screen.getByTestId("banks-detail__delete-button"));

    // Zero accounts → the existing destructive dialog, no guard surface.
    expect(await screen.findByTestId("banks-detail__delete-confirm")).toBeInTheDocument();
    expect(screen.queryByTestId("banks-detail__delete-guard-message")).toBeNull();
  });
});
