import { beforeEach, describe, expect, it, vi } from "vitest";
import { act, fireEvent, render, screen, waitFor } from "@testing-library/react";
import { HttpError } from "@/context/shared/http-client/domain/HttpError";

const held = vi.hoisted(() => ({ permissions: new Set<string>() }));
const list = vi.hoisted(() => vi.fn());
const attach = vi.hoisted(() => vi.fn());
const download = vi.hoisted(() => vi.fn());
const detach = vi.hoisted(() => vi.fn());
const saveBlob = vi.hoisted(() => vi.fn());

vi.mock("@/context/shared/access/application/useCan", () => ({
  useCan: (permission: string) => held.permissions.has(permission),
}));

vi.mock("@/context/shared/dependency-injection/infrastructure/Container", () => ({
  container: {
    get: (token: string) => {
      if (token === "BackOfficeListBankAccountEvidence") return { run: list };
      if (token === "BackOfficeAttachBankAccountEvidence") return { run: attach };
      if (token === "BackOfficeDownloadBankAccountEvidence") return { run: download };
      if (token === "BackOfficeDetachBankAccountEvidence") return { run: detach };
      throw new Error(`Unexpected container token: ${token}`);
    },
  },
}));

vi.mock("@/context/backoffice/document/infrastructure/saveBlob", () => ({ saveBlob }));

import { BankAccountEvidencePanel } from "@/app/backoffice/bank-accounts/_components/BankAccountEvidencePanel";

const ACCOUNT_ID = "0190e9c2-0000-7000-8000-000000000001";

const READY = {
  id: "0190e9c2-0000-7000-8000-0000000000aa",
  documentId: "0190e9c2-0000-7000-8000-0000000000bb",
  kind: "ownership-certificate",
  name: "certificate.pdf",
  state: "ready",
  digest: "a".repeat(64),
  sizeBytes: 2048,
  attachedAt: "2026-11-28T10:00:00+00:00",
} as const;

const ERASED = {
  ...READY,
  id: "0190e9c2-0000-7000-8000-0000000000cc",
  documentId: "0190e9c2-0000-7000-8000-0000000000dd",
  name: null,
  state: "erased",
} as const;

function grant(...permissions: string[]): void {
  held.permissions = new Set(permissions);
}

function pick(file: File): void {
  fireEvent.change(screen.getByTestId("bank-account-evidence__input"), {
    target: { files: [file] },
  });
}

describe("BankAccountEvidencePanel", () => {
  beforeEach(() => {
    vi.clearAllMocks();
    grant("document.custody", "document.write", "document.download", "bankAccount.write");
    list.mockResolvedValue([READY, ERASED]);
  });

  it("renders nothing, and reads nothing, without document.custody", () => {
    grant("document.write");

    const { container } = render(<BankAccountEvidencePanel bankAccountId={ACCOUNT_ID} />);

    expect(container).toBeEmptyDOMElement();
    expect(list).not.toHaveBeenCalled();
  });

  it("lists the evidence with its state, and an erased document as its tombstone", async () => {
    render(<BankAccountEvidencePanel bankAccountId={ACCOUNT_ID} />);

    expect(await screen.findByTestId(`bank-account-evidence__name-${READY.id}`)).toHaveTextContent(
      "certificate.pdf",
    );
    expect(screen.getByTestId(`bank-account-evidence__name-${ERASED.id}`)).toHaveTextContent(
      "Name unavailable",
    );
    expect(screen.getByTestId(`bank-account-evidence__state-${READY.id}`)).toHaveTextContent(
      "Ready",
    );
    expect(screen.getByTestId(`bank-account-evidence__state-${ERASED.id}`)).toHaveTextContent(
      "Erased",
    );
    expect(screen.getByTestId(`bank-account-evidence__download-${READY.id}`)).toBeInTheDocument();
    expect(screen.queryByTestId(`bank-account-evidence__download-${ERASED.id}`)).toBeNull();
    expect(list).toHaveBeenCalledWith(ACCOUNT_ID);
  });

  it("says the file is kept as sent, as opposed to a re-encoded image", async () => {
    render(<BankAccountEvidencePanel bankAccountId={ACCOUNT_ID} />);

    const help = await screen.findByTestId("bank-account-evidence__help");
    expect(help).toHaveTextContent("exactly as sent");
    expect(help).toHaveTextContent("never re-encoded");
    expect(help).toHaveTextContent("images are re-encoded and lose their metadata");
    expect(screen.getByTestId("bank-account-evidence__attach")).toHaveTextContent(
      "Attach evidence",
    );
  });

  it("offers no attach without document.write and no download without document.download", async () => {
    grant("document.custody", "bankAccount.write");

    render(<BankAccountEvidencePanel bankAccountId={ACCOUNT_ID} />);

    await screen.findByTestId(`bank-account-evidence__name-${READY.id}`);
    expect(screen.queryByTestId("bank-account-evidence__attach")).toBeNull();
    expect(screen.queryByTestId(`bank-account-evidence__detach-${READY.id}`)).toBeNull();
    expect(screen.queryByTestId(`bank-account-evidence__download-${READY.id}`)).toBeNull();
  });

  it("offers no attach and no detach without bankAccount.write, which both routes also demand", async () => {
    grant("document.custody", "document.write", "document.download");

    render(<BankAccountEvidencePanel bankAccountId={ACCOUNT_ID} />);

    await screen.findByTestId(`bank-account-evidence__name-${READY.id}`);
    expect(screen.queryByTestId("bank-account-evidence__attach")).toBeNull();
    expect(screen.queryByTestId(`bank-account-evidence__detach-${READY.id}`)).toBeNull();
    expect(screen.getByTestId(`bank-account-evidence__download-${READY.id}`)).toBeInTheDocument();
  });

  it("shows a kind or state this client does not know as unknown, and keeps the other items", async () => {
    list.mockResolvedValue([{ ...READY, kind: "unknown", state: "unknown" }, ERASED]);

    render(<BankAccountEvidencePanel bankAccountId={ACCOUNT_ID} />);

    expect(await screen.findByTestId(`bank-account-evidence__state-${READY.id}`)).toHaveTextContent(
      "Unknown",
    );
    expect(screen.getByTestId(`bank-account-evidence__item-${READY.id}`)).toHaveTextContent(
      "Unknown kind",
    );
    expect(screen.queryByTestId(`bank-account-evidence__download-${READY.id}`)).toBeNull();
    expect(screen.getByTestId(`bank-account-evidence__state-${ERASED.id}`)).toHaveTextContent(
      "Erased",
    );
  });

  it("keeps its status announcements outside the subtree it marks busy", async () => {
    render(<BankAccountEvidencePanel bankAccountId={ACCOUNT_ID} />);
    await screen.findByTestId(`bank-account-evidence__name-${READY.id}`);

    const status = screen.getByTestId("bank-account-evidence__status");
    expect(status).toHaveAttribute("role", "status");
    expect(status.closest("[aria-busy]")).toBeNull();
  });

  it("shows the empty state", async () => {
    list.mockResolvedValue([]);

    render(<BankAccountEvidencePanel bankAccountId={ACCOUNT_ID} />);

    expect(await screen.findByTestId("bank-account-evidence__empty")).toBeInTheDocument();
  });

  it("uploads with progress, attaches as the ownership certificate and reloads the list", async () => {
    let report: ((progress: { loaded: number; total: number }) => void) | undefined;
    let finish: (() => void) | undefined;
    attach.mockImplementation(
      (_id: string, _file: File, _kind: string, options: { onProgress: typeof report }) => {
        report = options.onProgress;
        return new Promise<void>((resolve) => {
          finish = resolve;
        });
      },
    );
    render(<BankAccountEvidencePanel bankAccountId={ACCOUNT_ID} />);
    await screen.findByTestId(`bank-account-evidence__name-${READY.id}`);
    const file = new File(["12345"], "certificate.pdf");

    pick(file);
    await waitFor(() => expect(report).toBeDefined());
    act(() => report?.({ loaded: 2, total: 4 }));

    expect(await screen.findByTestId("bank-account-evidence__progress")).toHaveAttribute(
      "value",
      "50",
    );
    expect(attach).toHaveBeenCalledWith(
      ACCOUNT_ID,
      file,
      "ownership-certificate",
      expect.objectContaining({ signal: expect.any(AbortSignal) }),
    );

    await act(async () => finish?.());

    await waitFor(() => expect(screen.queryByTestId("bank-account-evidence__upload")).toBeNull());
    expect(list).toHaveBeenCalledTimes(2);
    expect(screen.getByTestId("bank-account-evidence__status")).toHaveTextContent(
      "certificate.pdf attached as evidence.",
    );
  });

  it("refuses an empty file before any byte is sent", async () => {
    render(<BankAccountEvidencePanel bankAccountId={ACCOUNT_ID} />);
    await screen.findByTestId(`bank-account-evidence__name-${READY.id}`);

    pick(new File([], "empty.pdf"));

    expect(await screen.findByTestId("bank-account-evidence__refusal")).toHaveTextContent(
      "This file is empty.",
    );
    expect(attach).not.toHaveBeenCalled();
  });

  it("surfaces a retention mismatch in the persistent error surface, in plain English", async () => {
    attach.mockRejectedValue(
      new HttpError({
        type: "bank-account-evidence-retention-mismatch",
        title: "server title",
        status: 422,
        instance: "i",
        "correlation-id": "c",
      }),
    );
    render(<BankAccountEvidencePanel bankAccountId={ACCOUNT_ID} />);
    await screen.findByTestId(`bank-account-evidence__name-${READY.id}`);

    pick(new File(["1"], "certificate.pdf"));

    const error = await screen.findByTestId("bank-account-evidence__error");
    expect(error).toHaveTextContent("disagree on how this kind of evidence is kept");
    expect(error).toHaveTextContent("Contact an administrator.");
    expect(error).not.toHaveTextContent("attach it again");
    expect(error).toHaveTextContent('"certificate.pdf" was not attached.');
  });

  it("reloads the list after a failed attach, since a document may have been filed or linked", async () => {
    attach.mockRejectedValue(
      new HttpError({
        type: "network-error",
        title: "Network error",
        status: 0,
        instance: "i",
        "correlation-id": "c",
      }),
    );
    render(<BankAccountEvidencePanel bankAccountId={ACCOUNT_ID} />);
    await screen.findByTestId(`bank-account-evidence__name-${READY.id}`);

    pick(new File(["1"], "certificate.pdf"));

    const error = await screen.findByTestId("bank-account-evidence__error");
    expect(error).toHaveTextContent("The outcome is unknown.");
    expect(error).toHaveTextContent("Check the list below before trying again.");
    await waitFor(() => expect(list).toHaveBeenCalledTimes(2));
  });

  it("stops offering a cancel once the original is filed", async () => {
    let filed: (() => void) | undefined;
    attach.mockImplementation(
      (_id: string, _file: File, _kind: string, options: { onFiled: () => void }) => {
        filed = options.onFiled;
        return new Promise<void>(() => undefined);
      },
    );
    render(<BankAccountEvidencePanel bankAccountId={ACCOUNT_ID} />);
    await screen.findByTestId(`bank-account-evidence__name-${READY.id}`);

    pick(new File(["1"], "certificate.pdf"));
    expect(await screen.findByTestId("bank-account-evidence__cancel")).toBeInTheDocument();
    await waitFor(() => expect(filed).toBeDefined());
    act(() => filed?.());

    await waitFor(() => expect(screen.queryByTestId("bank-account-evidence__cancel")).toBeNull());
    expect(screen.getByTestId("bank-account-evidence__upload")).toHaveTextContent(
      "Attaching certificate.pdf",
    );
  });

  it("cancels an upload without reporting an error", async () => {
    attach.mockImplementation(
      (_id: string, _file: File, _kind: string, options: { signal: AbortSignal }) =>
        new Promise((_resolve, reject) => {
          options.signal.addEventListener("abort", () =>
            reject(new DOMException("cancelled", "AbortError")),
          );
        }),
    );
    render(<BankAccountEvidencePanel bankAccountId={ACCOUNT_ID} />);
    await screen.findByTestId(`bank-account-evidence__name-${READY.id}`);

    pick(new File(["1"], "certificate.pdf"));
    fireEvent.click(await screen.findByTestId("bank-account-evidence__cancel"));

    await waitFor(() =>
      expect(screen.getByTestId("bank-account-evidence__status")).toHaveTextContent(
        "Upload cancelled.",
      ),
    );
    expect(screen.queryByTestId("bank-account-evidence__error")).toBeNull();
  });

  it("downloads a ready document under its own name", async () => {
    const blob = new Blob(["%PDF"]);
    download.mockResolvedValue({ blob, filename: "certificate.pdf" });
    render(<BankAccountEvidencePanel bankAccountId={ACCOUNT_ID} />);

    fireEvent.click(await screen.findByTestId(`bank-account-evidence__download-${READY.id}`));

    await waitFor(() => expect(saveBlob).toHaveBeenCalledWith(blob, "certificate.pdf"));
    expect(download).toHaveBeenCalledWith(
      expect.objectContaining({ documentId: READY.documentId }),
    );
  });

  it("detaches a link behind a confirmation and reloads the list", async () => {
    detach.mockResolvedValue(undefined);
    render(<BankAccountEvidencePanel bankAccountId={ACCOUNT_ID} />);

    fireEvent.click(await screen.findByTestId(`bank-account-evidence__detach-${READY.id}`));
    fireEvent.click(await screen.findByTestId(`bank-account-evidence__detach-confirm-${READY.id}`));

    await waitFor(() => expect(detach).toHaveBeenCalledWith(ACCOUNT_ID, READY.id));
    await waitFor(() => expect(list).toHaveBeenCalledTimes(2));
    expect(screen.getByTestId("bank-account-evidence__status")).toHaveTextContent(
      "certificate.pdf detached.",
    );
  });

  it("surfaces a refused detach in the persistent error surface and reloads the list", async () => {
    detach.mockRejectedValue(
      new HttpError({
        type: "bank-account-evidence-not-found",
        title: "The evidence is not attached to this bank account.",
        status: 404,
        instance: "i",
        "correlation-id": "c",
      }),
    );
    render(<BankAccountEvidencePanel bankAccountId={ACCOUNT_ID} />);

    fireEvent.click(await screen.findByTestId(`bank-account-evidence__detach-${READY.id}`));
    fireEvent.click(await screen.findByTestId(`bank-account-evidence__detach-confirm-${READY.id}`));

    expect(await screen.findByTestId("bank-account-evidence__error")).toHaveTextContent(
      "The evidence is not attached to this bank account.",
    );
    await waitFor(() => expect(list).toHaveBeenCalledTimes(2));
  });

  it("shows the problem when the list cannot be read", async () => {
    list.mockRejectedValue(
      new HttpError({
        type: "gone",
        title: "The document is gone.",
        status: 410,
        instance: "i",
        "correlation-id": "c",
      }),
    );

    render(<BankAccountEvidencePanel bankAccountId={ACCOUNT_ID} />);

    expect(await screen.findByTestId("bank-account-evidence__load-error")).toHaveTextContent(
      "The document is gone.",
    );
  });
});
