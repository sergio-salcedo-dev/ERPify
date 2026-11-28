import { beforeEach, describe, expect, it, vi } from "vitest";
import { fireEvent, render, screen, waitFor } from "@testing-library/react";
import BankAccountDetailPage from "@/app/backoffice/bank-accounts/[id]/page";
import { BankAccount } from "@/context/backoffice/bankaccount/domain/BankAccount";
import { HttpError } from "@/context/shared/http-client/domain/HttpError";

const ACCOUNT_ID = vi.hoisted(() => "aaaaaaaa-aaaa-4aaa-8aaa-000000000001");

const CLOSED_ACCOUNT = BankAccount.fromPrimitives({
  id: ACCOUNT_ID,
  holderName: "Alice Holder",
  iban: "ES9121000418450200051332",
  bic: null,
  alias: null,
  currency: "EUR",
  status: "CLOSED",
  bankId: "11111111-1111-4111-8111-111111111111",
  createdAt: "2026-01-01T10:00:00Z",
  updatedAt: "2026-02-01T10:00:00Z",
});

vi.mock("next/navigation", async () =>
  (await import("./_mocks")).routerMock({}, { id: ACCOUNT_ID }),
);

const run = vi.hoisted(() => vi.fn());
const deleteRun = vi.hoisted(() => vi.fn());
const listRun = vi.hoisted(() => vi.fn());
vi.mock("@/context/shared/dependency-injection/infrastructure/Container", async () =>
  (await import("./_mocks")).containerMock({
    BackOfficeFindBankAccount: { run },
    BackOfficeDeleteBankAccount: { run: deleteRun },
    BackOfficeListBankAccountEvidence: { run: listRun },
  }),
);

vi.mock("@/context/shared/notification/infrastructure/Toast", async () =>
  (await import("./_mocks")).toastNotifierMock(),
);

vi.mock("@/context/shared/access/application/useCan", async () =>
  (await import("./_mocks")).useCanMock(["bankAccount.readEvidence"]),
);

vi.mock("@/context/backoffice/bankaccount/infrastructure/bankAccountRealtime", async () =>
  (await import("./_mocks")).bankAccountRealtimeMock(),
);

describe("BankAccountDetailPage — evidence", () => {
  beforeEach(() => {
    vi.clearAllMocks();
    run.mockResolvedValue(CLOSED_ACCOUNT);
    listRun.mockResolvedValue([]);
  });

  it("renders the evidence section for a reader, without the attach control", async () => {
    render(<BankAccountDetailPage />);

    expect(await screen.findByTestId("bank-accounts-detail__evidence-empty")).toBeVisible();
    expect(listRun).toHaveBeenCalledWith(ACCOUNT_ID);
    expect(screen.queryByTestId("bank-accounts-detail__evidence-attach")).not.toBeInTheDocument();
  });

  it("explains a delete refused for live evidence and links to the evidence", async () => {
    deleteRun.mockRejectedValue(
      new HttpError({
        type: "bank-account-has-live-evidence",
        title: "Server title",
        status: 409,
        instance: "i",
        "correlation-id": "c",
        liveDocuments: 2,
      }),
    );
    render(<BankAccountDetailPage />);

    fireEvent.click(await screen.findByTestId("bank-accounts-detail__delete-button"));
    fireEvent.click(await screen.findByTestId("bank-accounts-detail__delete-confirm"));

    const surface = await screen.findByTestId("bank-accounts-detail__delete-error");
    expect(surface).toHaveTextContent(
      "This account still holds 2 evidence documents that are not erased. Each document must be erased before the account can be deleted.",
    );
    expect(surface).toHaveTextContent(
      "Erasing a document is an administrator action that is not available on this screen.",
    );
    await waitFor(() => expect(deleteRun).toHaveBeenCalledWith(ACCOUNT_ID));
    expect(screen.getByTestId("bank-accounts-detail__delete-error-evidence")).toHaveAttribute(
      "href",
      "#bank-account-evidence",
    );
  });
});
