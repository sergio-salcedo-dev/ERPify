import { beforeEach, describe, expect, it, vi } from "vitest";
import { fireEvent, render, screen, waitFor } from "@testing-library/react";
import BankDetailPage from "@/app/backoffice/banks/[id]/page";
import { Bank } from "@/context/backoffice/bank/domain/Bank";
import { toastNotifier } from "@/context/shared/infrastructure/Notification/Toast";

/**
 * deleting a  bank from its detail page must redirect cleanly to the list and surface a
 * success toast, without flashing the "Bank not found" empty state while the
 * navigation settles.
 */

const BANK = Bank.fromPrimitives({
  id: "11111111-1111-4111-8111-111111111111",
  name: "Acme Savings",
  shortName: "ACME",
  createdAt: "2026-01-01T10:00:00Z",
  updatedAt: "2026-04-15T14:30:00Z",
});

const push = vi.fn();
const refresh = vi.fn();

vi.mock("next/navigation", () => ({
  useParams: () => ({ id: BANK.id }),
  useRouter: () => ({ push, refresh, back: vi.fn() }),
}));

const findRun = vi.fn();
const deleteRun = vi.fn();

vi.mock("@/context/shared/infrastructure/DependencyInjection/Container", () => ({
  container: {
    get: (token: string) => {
      if (token === "BackOfficeFindBank") return { run: findRun };
      if (token === "BackOfficeDeleteBank") return { run: deleteRun };
      throw new Error(`Unexpected DI token ${token}`);
    },
  },
}));

vi.mock("@/context/shared/infrastructure/Notification/Toast", () => ({
  toastNotifier: { success: vi.fn(), error: vi.fn(), info: vi.fn(), warning: vi.fn() },
}));

describe("BankDetailPage — delete UX", () => {
  beforeEach(() => {
    vi.clearAllMocks();
    findRun.mockResolvedValue(BANK);
    deleteRun.mockResolvedValue(undefined);
  });

  it("shows a success toast and redirects to the list after a successful delete", async () => {
    render(<BankDetailPage />);
    expect(await screen.findByTestId("banks-detail__name")).toHaveTextContent("Acme Savings");

    fireEvent.click(screen.getByTestId("banks-detail__delete-button"));
    fireEvent.click(await screen.findByTestId("banks-detail__delete-confirm"));

    await waitFor(() => {
      expect(toastNotifier.success).toHaveBeenCalledWith("Bank deleted", {
        description: "Acme Savings",
      });
    });
    expect(push).toHaveBeenCalledWith("/backoffice/banks");
  });

  it("redirects cleanly without re-triggering the detail fetch and never flashes 'Bank not found'", async () => {
    render(<BankDetailPage />);
    expect(await screen.findByTestId("banks-detail__name")).toHaveTextContent("Acme Savings");

    fireEvent.click(screen.getByTestId("banks-detail__delete-button"));
    fireEvent.click(await screen.findByTestId("banks-detail__delete-confirm"));

    await waitFor(() => {
      expect(push).toHaveBeenCalledWith("/backoffice/banks");
    });
    // A clean redirect must not call router.refresh(): that is what re-ran the
    // FindBank fetch for the deleted id and flashed the not-found state.
    expect(refresh).not.toHaveBeenCalled();
    expect(screen.queryByTestId("banks-detail__not-found")).toBeNull();
  });
});
