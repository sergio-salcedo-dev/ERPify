import { beforeEach, describe, expect, it, vi } from "vitest";
import { render, screen } from "@testing-library/react";
import BankAccountsListPage from "@/app/backoffice/bank-accounts/page";

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

describe("BankAccountsListPage — loading skeleton", () => {
  beforeEach(() => {
    vi.clearAllMocks();
  });

  it("shows the list skeleton while the search is in flight", () => {
    // Never resolves → page stays in LOADING.
    search.mockReturnValue(new Promise(() => {}));
    render(<BankAccountsListPage />);
    expect(screen.getByTestId("bank-accounts-list__skeleton")).toBeInTheDocument();
  });
});
