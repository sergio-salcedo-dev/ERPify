import { beforeEach, describe, expect, it, vi } from "vitest";
import { act, render, screen, waitFor } from "@testing-library/react";
import BankDetailPage from "@/app/backoffice/banks/[id]/page";
import { Bank } from "@/context/backoffice/bank/domain/Bank";
import type { BankRealtimeHandlers } from "@/context/backoffice/bank/infrastructure/bankRealtime";

const LOGO = "0190a1b2-c3d4-7e5f-8a9b-0c1d2e3f4a01";

const BANK = Bank.fromPrimitives({
  id: "77777777-7777-4777-8777-777777777777",
  name: "Santander Bank",
  shortName: "SANB",
  createdAt: "2020-01-01T00:00:00.000Z",
  updatedAt: "2020-01-02T00:00:00.000Z",
  accountCount: 0,
  logoImageId: LOGO,
});

const realtime = vi.hoisted(() => ({ handlers: null as BankRealtimeHandlers | null }));

vi.mock("next/navigation", () => ({
  useParams: () => ({ id: "77777777-7777-4777-8777-777777777777" }),
  useRouter: () => ({ push: vi.fn() }),
}));

const run = vi.fn();
vi.mock("@/context/shared/dependency-injection/infrastructure/Container", () => ({
  container: { get: () => ({ run }) },
}));

vi.mock("@/context/backoffice/bank/infrastructure/bankRealtime", async () =>
  (await import("./_mocks")).bankRealtimeMock((handlers) => {
    realtime.handlers = handlers;
  }),
);

/**
 * An open detail page replaces its whole state with a realtime `updated` payload, so the logo the page shows after
 * another client's rename is whatever that payload carries: the API resolves `logoImageId` at publish time exactly so
 * a rename keeps it, and a payload carrying `null` is how a removal reaches the page.
 */
describe("Bank detail — realtime update and the logo", () => {
  beforeEach(() => {
    vi.clearAllMocks();
    realtime.handlers = null;
    run.mockResolvedValue(BANK);
  });

  function update(incoming: Bank): void {
    act(() => {
      realtime.handlers?.onUpdated?.(incoming);
    });
  }

  it("keeps the logo when another client renames the bank and the payload carries the same id", async () => {
    render(<BankDetailPage />);
    expect(await screen.findByTestId("banks-detail__logo__image")).toHaveAttribute(
      "src",
      expect.stringContaining(LOGO),
    );

    update(Bank.fromPrimitives({ ...BANK, name: "Santander Renamed" }));

    await waitFor(() =>
      expect(screen.getByTestId("banks-detail__name")).toHaveTextContent("Santander Renamed"),
    );
    expect(screen.getByTestId("banks-detail__logo__image")).toHaveAttribute(
      "src",
      expect.stringContaining(LOGO),
    );
  });

  it("drops the logo when the payload carries null", async () => {
    render(<BankDetailPage />);
    await screen.findByTestId("banks-detail__logo__image");

    update(Bank.fromPrimitives({ ...BANK, logoImageId: null }));

    expect(await screen.findByTestId("banks-detail__logo__placeholder")).toBeInTheDocument();
    expect(screen.queryByTestId("banks-detail__logo__image")).toBeNull();
  });

  it("ignores an update addressed to another bank", async () => {
    render(<BankDetailPage />);
    await screen.findByTestId("banks-detail__logo__image");

    update(
      Bank.fromPrimitives({
        ...BANK,
        id: "88888888-8888-4888-8888-888888888888",
        logoImageId: null,
      }),
    );

    expect(screen.getByTestId("banks-detail__logo__image")).toBeInTheDocument();
  });
});
