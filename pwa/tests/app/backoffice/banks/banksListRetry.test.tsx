import { beforeEach, describe, expect, it, vi } from "vitest";
import { fireEvent, render, screen, waitFor } from "@testing-library/react";
import BanksListPage from "@/app/backoffice/banks/page";
import { Bank } from "@/context/backoffice/bank/domain/Bank";

vi.mock("next/navigation", async () => (await import("./_mocks")).routerMock());

const ACME = Bank.fromPrimitives({
  id: "11111111-1111-4111-8111-111111111111",
  name: "Acme Savings",
  shortName: "ACME",
  createdAt: "2026-01-01T10:00:00Z",
  updatedAt: "2026-04-15T14:30:00Z",
});

const searchRun = vi.hoisted(() => vi.fn());
vi.mock("@/context/shared/infrastructure/DependencyInjection/Container", async () =>
  (await import("./_mocks")).containerMock({ BackOfficeSearchBanks: { run: searchRun } }),
);

vi.mock("@/context/shared/infrastructure/Notification/Toast", async () =>
  (await import("./_mocks")).toastNotifierMock(),
);

describe("BanksListPage — retry on error", () => {
  beforeEach(() => {
    vi.clearAllMocks();
  });

  it("re-runs the search when Retry is clicked after an error", async () => {
    searchRun
      .mockRejectedValueOnce(new Error("network down"))
      .mockResolvedValueOnce({ banks: [ACME], nextCursor: undefined });

    render(<BanksListPage />);

    const retry = await screen.findByTestId("banks-list__retry");
    expect(searchRun).toHaveBeenCalledTimes(1);

    fireEvent.click(retry);

    await waitFor(() => {
      expect(screen.getByRole("cell", { name: "Acme Savings" })).toBeInTheDocument();
    });
    expect(searchRun).toHaveBeenCalledTimes(2);
  });
});
