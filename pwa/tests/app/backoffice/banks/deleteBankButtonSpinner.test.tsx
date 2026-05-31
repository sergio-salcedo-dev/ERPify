import { beforeEach, describe, expect, it, vi } from "vitest";
import { fireEvent, render, screen, waitFor } from "@testing-library/react";
import { DeleteBankButton } from "@/app/backoffice/banks/_components/DeleteBankButton";

vi.mock("next/navigation", () => ({
  useRouter: () => ({ push: vi.fn(), refresh: vi.fn(), back: vi.fn() }),
}));

const deleteRun = vi.fn();
vi.mock("@/context/shared/infrastructure/DependencyInjection/Container", () => ({
  container: {
    get: (token: string) => {
      if (token === "BackOfficeDeleteBank") return { run: deleteRun };
      throw new Error(`Unexpected DI token ${token}`);
    },
  },
}));

describe("DeleteBankButton — spinner", () => {
  beforeEach(() => {
    vi.clearAllMocks();
  });

  it("shows a 'Deleting…' spinner while the delete is in flight", async () => {
    let resolveDelete: () => void = () => {};
    deleteRun.mockReturnValue(
      new Promise<void>((resolve) => {
        resolveDelete = resolve;
      }),
    );
    const onDeleted = vi.fn();
    render(<DeleteBankButton id="abc" name="Acme Savings" onDeleted={onDeleted} />);

    fireEvent.click(screen.getByTestId("banks-detail__delete-button"));
    fireEvent.click(screen.getByTestId("banks-detail__delete-confirm"));

    expect(await screen.findByTestId("banks-detail__delete-spinner")).toBeInTheDocument();

    resolveDelete();
    await waitFor(() => {
      expect(onDeleted).toHaveBeenCalledWith("abc");
    });
  });
});
