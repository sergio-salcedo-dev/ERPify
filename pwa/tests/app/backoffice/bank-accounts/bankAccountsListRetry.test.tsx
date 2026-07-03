import { beforeEach, describe, expect, it, vi } from "vitest";
import { fireEvent, render, screen, waitFor } from "@testing-library/react";
import BankAccountsListPage from "@/app/backoffice/bank-accounts/page";
import { ACME_CHECKING, resourcePage } from "./_fixtures";

vi.mock("next/navigation", async () => (await import("./_mocks")).routerMock());

const search = vi.hoisted(() => vi.fn());
vi.mock("@/context/shared/dependency-injection/infrastructure/Container", async () =>
  (await import("./_mocks")).containerMock({
    BackOfficeBankAccountCrudRepository: { search },
  }),
);

vi.mock("@/context/shared/notification/infrastructure/Toast", async () =>
  (await import("./_mocks")).toastNotifierMock(),
);

vi.mock("@/context/backoffice/bankaccount/infrastructure/bankAccountRealtime", async () =>
  (await import("./_mocks")).bankAccountRealtimeMock(),
);

describe("BankAccountsListPage — retry on error", () => {
  beforeEach(() => {
    vi.clearAllMocks();
  });

  it("re-runs the search when Retry is clicked after an error", async () => {
    search
      .mockRejectedValueOnce(new Error("network down"))
      .mockResolvedValueOnce(resourcePage([ACME_CHECKING]));

    render(<BankAccountsListPage />);

    const retry = await screen.findByTestId("bank-accounts-list__retry");
    expect(search).toHaveBeenCalledTimes(1);

    fireEvent.click(retry);

    await waitFor(() => {
      expect(screen.getByRole("cell", { name: "Alice Holder" })).toBeInTheDocument();
    });
    expect(search).toHaveBeenCalledTimes(2);
  });
});
