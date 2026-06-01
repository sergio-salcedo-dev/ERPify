import { beforeEach, describe, expect, it, vi } from "vitest";
import { render, screen, waitFor } from "@testing-library/react";
import BanksListPage from "@/app/backoffice/banks/page";
import { Bank } from "@/context/backoffice/bank/domain/Bank";

vi.mock("next/navigation", () => ({
  useRouter: () => ({ push: vi.fn(), refresh: vi.fn() }),
}));

const run = vi.fn();
vi.mock("@/context/shared/infrastructure/DependencyInjection/Container", () => ({
  container: { get: () => ({ run }) },
}));

const RECENT = Bank.fromPrimitives({
  id: "55555555-5555-4555-8555-555555555555",
  name: "Santander Bank",
  shortName: "SANB",
  createdAt: new Date().toISOString(),
  updatedAt: new Date().toISOString(),
});
const OLD = Bank.fromPrimitives({
  id: "66666666-6666-4666-8666-666666666666",
  name: "Caixa Bank",
  shortName: "CAIX",
  createdAt: "2020-01-01T00:00:00.000Z",
  updatedAt: "2020-01-01T00:00:00.000Z",
});

describe("Banks list — header total", () => {
  beforeEach(() => {
    vi.clearAllMocks();
  });

  it("shows the total count and how many were added in the recency window", async () => {
    run.mockResolvedValue({ banks: [RECENT, OLD], nextCursor: undefined });
    render(<BanksListPage />);
    const total = await screen.findByTestId("banks-list__total");
    await waitFor(() => {
      expect(total.textContent).toContain("2");
      expect(total.textContent).toContain("1 added this week");
    });
  });
});
