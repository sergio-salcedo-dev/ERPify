import { beforeEach, describe, expect, it, vi } from "vitest";
import { fireEvent, render, screen, waitFor } from "@testing-library/react";
import EditBankPage from "@/app/backoffice/banks/[id]/edit/page";
import { Bank } from "@/context/backoffice/bank/domain/Bank";
import { ACME, BETA } from "./_fixtures";
import { BANK_READER, BANK_WRITER, WithSession } from "./_session";

/**
 * The bank logo control of the edit screen: shown only to a session holding `bank.write`, owned by
 * the page (seeded from the loaded bank, then set from the write's own answer), and sitting outside
 * the bank form so a name typed and not yet saved survives a logo change.
 */

const BANK_ID = ACME.id;
const OLD_LOGO = "0190a1b2-c3d4-7e5f-8a9b-0c1d2e3f4a01";
const NEW_LOGO = "0190a1b2-c3d4-7e5f-8a9b-0c1d2e3f4a02";

const FIRST_ROUTE_ID = "11111111-1111-4111-8111-111111111111";
const SECOND_ROUTE_ID = "22222222-2222-4222-8222-222222222222";
const route = vi.hoisted(() => ({ id: "11111111-1111-4111-8111-111111111111" }));
vi.mock("next/navigation", async () => ({
  ...(await import("./_mocks")).routerMock(),
  useParams: () => ({ id: route.id }),
}));

const findRun = vi.hoisted(() => vi.fn());
const upload = vi.hoisted(() => vi.fn());
const remove = vi.hoisted(() => vi.fn());
vi.mock("@/context/shared/dependency-injection/infrastructure/Container", async () =>
  (await import("./_mocks")).containerMock({
    BackOfficeFindBank: { run: findRun },
    BackOfficeUpdateBank: { run: vi.fn() },
    ImageUploader: { upload, remove },
  }),
);

vi.mock("@/context/shared/notification/infrastructure/Toast", async () =>
  (await import("./_mocks")).toastNotifierMock(),
);

function bankHolding(logoImageId: string | null): Bank {
  return Bank.fromPrimitives({ ...ACME, logoImageId });
}

function renderPage(session = BANK_WRITER) {
  return render(
    <WithSession session={session}>
      <EditBankPage />
    </WithSession>,
  );
}

function pick(file: File): void {
  fireEvent.change(screen.getByTestId("banks-edit__logo-field__input"), {
    target: { files: [file] },
  });
}

const PNG = () => new File([new Uint8Array([137, 80, 78, 71])], "logo.png", { type: "image/png" });

describe("EditBankPage — logo control", () => {
  beforeEach(() => {
    vi.clearAllMocks();
    route.id = FIRST_ROUTE_ID;
    findRun.mockResolvedValue(bankHolding(OLD_LOGO));
  });

  it("offers the control to a session holding bank.write, showing the logo the bank holds", async () => {
    renderPage();

    expect(await screen.findByTestId("banks-edit__logo")).toBeInTheDocument();
    expect(screen.getByTestId("banks-edit__logo-field__preview__image")).toHaveAttribute(
      "src",
      expect.stringContaining(OLD_LOGO),
    );
    expect(screen.getByTestId("banks-edit__logo-field__remove")).toBeInTheDocument();
  });

  it("shows no control to a session without bank.write", async () => {
    renderPage(BANK_READER);

    await screen.findByTestId("bank-form");
    expect(screen.queryByTestId("banks-edit__logo")).not.toBeInTheDocument();
    expect(screen.queryByTestId("banks-edit__logo-field")).not.toBeInTheDocument();
  });

  it("writes to the bank's own logo route and shows the id the write answered, with no refetch", async () => {
    upload.mockResolvedValue({ imageId: NEW_LOGO });
    renderPage();
    await screen.findByTestId("banks-edit__logo");

    pick(PNG());

    await waitFor(() =>
      expect(screen.getByTestId("banks-edit__logo-field__preview__image")).toHaveAttribute(
        "src",
        expect.stringContaining(NEW_LOGO),
      ),
    );
    expect(upload).toHaveBeenCalledTimes(1);
    expect(upload.mock.calls[0][0]).toBe(`/api/v1/backoffice/banks/${BANK_ID}/logo`);
    expect(findRun).toHaveBeenCalledTimes(1);
  });

  it("removes the logo through the same route and falls back to the placeholder", async () => {
    remove.mockResolvedValue(undefined);
    renderPage();

    fireEvent.click(await screen.findByTestId("banks-edit__logo-field__remove"));

    await waitFor(() =>
      expect(screen.queryByTestId("banks-edit__logo-field__remove")).not.toBeInTheDocument(),
    );
    expect(remove).toHaveBeenCalledWith(`/api/v1/backoffice/banks/${BANK_ID}/logo`);
    expect(screen.getByTestId("banks-edit__logo-field__preview__placeholder")).toBeInTheDocument();
    expect(findRun).toHaveBeenCalledTimes(1);
  });

  it("keeps a name typed and not yet saved when the logo changes", async () => {
    upload.mockResolvedValue({ imageId: NEW_LOGO });
    renderPage();
    const name = await screen.findByTestId("bank-form__name");

    fireEvent.change(name, { target: { value: "Typed, not saved" } });
    pick(PNG());

    await waitFor(() =>
      expect(screen.getByTestId("banks-edit__logo-field__preview__image")).toHaveAttribute(
        "src",
        expect.stringContaining(NEW_LOGO),
      ),
    );
    expect(screen.getByTestId("bank-form__name")).toHaveValue("Typed, not saved");
  });

  it("drops the answer of an upload that settles after the route moved to another bank", async () => {
    let settle: (answer: { imageId: string }) => void = () => {};
    upload.mockImplementation(
      () =>
        new Promise<{ imageId: string }>((resolve) => {
          settle = resolve;
        }),
    );
    findRun.mockImplementation((id: string) =>
      Promise.resolve(
        id === SECOND_ROUTE_ID
          ? Bank.fromPrimitives({ ...BETA, logoImageId: null })
          : bankHolding(OLD_LOGO),
      ),
    );
    const { rerender } = renderPage();
    await screen.findByTestId("banks-edit__logo");
    pick(PNG());
    await waitFor(() => expect(upload).toHaveBeenCalledTimes(1));

    route.id = SECOND_ROUTE_ID;
    rerender(
      <WithSession session={BANK_WRITER}>
        <EditBankPage />
      </WithSession>,
    );
    await waitFor(() =>
      expect(screen.getByTestId("banks-edit__subtitle")).toHaveTextContent(BETA.name),
    );
    settle({ imageId: NEW_LOGO });

    await waitFor(() => expect(findRun).toHaveBeenCalledWith(SECOND_ROUTE_ID));
    await new Promise((resolve) => setTimeout(resolve, 0));
    expect(screen.getByTestId("banks-edit__logo-field__preview__placeholder")).toBeInTheDocument();
    expect(screen.queryByTestId("banks-edit__logo-field__preview__image")).toBeNull();
  });
});
