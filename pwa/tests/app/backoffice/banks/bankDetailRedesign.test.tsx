import { beforeEach, describe, expect, it, vi } from "vitest";
import { render, screen } from "@testing-library/react";
import BankDetailPage from "@/app/backoffice/banks/[id]/page";
import { Bank } from "@/context/backoffice/bank/domain/Bank";

const BANK = Bank.fromPrimitives({
  id: "77777777-7777-4777-8777-777777777777",
  name: "Santander Bank",
  shortName: "SANB",
  createdAt: "2020-01-01T00:00:00.000Z",
  updatedAt: "2020-01-02T00:00:00.000Z",
  accountCount: 0,
});

vi.mock("next/navigation", () => ({
  useParams: () => ({ id: BANK.id }),
  useRouter: () => ({ push: vi.fn() }),
}));

const run = vi.fn();
vi.mock("@/context/shared/dependency-injection/infrastructure/Container", () => ({
  container: { get: () => ({ run }) },
}));

describe("Bank detail — redesign", () => {
  beforeEach(() => {
    vi.clearAllMocks();
    run.mockResolvedValue(BANK);
  });

  it("renders Created as relative text with the absolute value in the title", async () => {
    render(<BankDetailPage />);
    const created = await screen.findByTestId("banks-detail__field-created");
    expect(created.textContent).toMatch(/ago$/);
    expect(created).toHaveAttribute("title", expect.stringContaining("2020"));
  });

  it("moves the copy control to the Identifier row and drops the header copy button", async () => {
    render(<BankDetailPage />);
    await screen.findByTestId("banks-detail__name");
    expect(screen.queryByTestId("banks-detail__copy-id")).toBeNull();
    expect(screen.getByTestId("banks-detail__id-copy")).toBeInTheDocument();
  });

  it("shows the New badge when the bank was created within the recency window", async () => {
    run.mockResolvedValue(
      Bank.fromPrimitives({
        ...BANK,
        createdAt: new Date().toISOString(),
        updatedAt: new Date().toISOString(),
      }),
    );
    render(<BankDetailPage />);
    expect(await screen.findByTestId("banks-detail__new-badge")).toHaveTextContent("New");
  });

  it("shows the logo the bank holds beside the name, and the placeholder when it holds none", async () => {
    const logoImageId = "0190a1b2-c3d4-7e5f-8a9b-0c1d2e3f4a01";
    run.mockResolvedValue(Bank.fromPrimitives({ ...BANK, logoImageId }));
    const { unmount } = render(<BankDetailPage />);
    const image = await screen.findByTestId("banks-detail__logo__image");
    expect(image).toHaveAttribute("src", expect.stringContaining(logoImageId));
    expect(image).toHaveAttribute("alt", "");
    unmount();

    run.mockResolvedValue(BANK);
    render(<BankDetailPage />);
    expect(await screen.findByTestId("banks-detail__logo__placeholder")).toBeInTheDocument();
    expect(screen.queryByTestId("banks-detail__logo__image")).toBeNull();
  });
});
