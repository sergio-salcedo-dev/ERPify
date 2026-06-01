import { beforeEach, describe, expect, it, vi } from "vitest";
import { render, screen } from "@testing-library/react";
import BanksListPage from "@/app/backoffice/banks/page";

vi.mock("next/navigation", () => ({
  useRouter: () => ({ push: vi.fn(), refresh: vi.fn(), back: vi.fn() }),
}));

const searchRun = vi.fn();
vi.mock("@/context/shared/infrastructure/DependencyInjection/Container", () => ({
  container: {
    get: (token: string) => {
      if (token === "BackOfficeSearchBanks") return { run: searchRun };
      throw new Error(`Unexpected DI token ${token}`);
    },
  },
}));

vi.mock("@/context/shared/infrastructure/Notification/Toast", () => ({
  toastNotifier: { success: vi.fn(), error: vi.fn(), info: vi.fn(), warning: vi.fn() },
}));

describe("BanksListPage — loading skeleton", () => {
  beforeEach(() => {
    vi.clearAllMocks();
  });

  it("shows the list skeleton while the search is in flight", () => {
    // Never resolves → page stays in LOADING.
    searchRun.mockReturnValue(new Promise(() => {}));
    render(<BanksListPage />);
    expect(screen.getByTestId("banks-list__skeleton")).toBeInTheDocument();
  });
});
