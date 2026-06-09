import { beforeEach, describe, expect, it, vi } from "vitest";
import { fireEvent, render, screen, waitFor } from "@testing-library/react";
import BanksListPage from "@/app/backoffice/banks/page";
import { ACME, searchPage } from "./_fixtures";

vi.mock("next/navigation", async () => (await import("./_mocks")).routerMock());

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
      .mockResolvedValueOnce(searchPage([ACME]));

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
