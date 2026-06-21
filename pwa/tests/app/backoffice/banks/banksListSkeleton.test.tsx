import { beforeEach, describe, expect, it, vi } from "vitest";
import { render, screen } from "@testing-library/react";
import BanksListPage from "@/app/backoffice/banks/page";

vi.mock("next/navigation", async () => (await import("./_mocks")).routerMock());

const searchRun = vi.hoisted(() => vi.fn());
vi.mock("@/context/shared/dependency-injection/infrastructure/Container", async () =>
  (await import("./_mocks")).containerMock({ BackOfficeSearchBanks: { run: searchRun } }),
);

vi.mock("@/context/shared/notification/infrastructure/Toast", async () =>
  (await import("./_mocks")).toastNotifierMock(),
);

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
