import { beforeEach, describe, expect, it, vi } from "vitest";
import { fireEvent, render, screen, waitFor } from "@testing-library/react";
import { BankAccountEvidenceSection } from "@/app/backoffice/bank-accounts/[id]/_components/BankAccountEvidenceSection";
import { EvidenceAttachFailure } from "@/context/backoffice/bankaccount/application/AttachBankAccountEvidence";
import type { BankAccountEvidence } from "@/context/backoffice/bankaccount/domain/BankAccountEvidence";
import { HttpError } from "@/context/shared/http-client/domain/HttpError";

const ACCOUNT_ID = "0192aaaa-aaaa-7aaa-8aaa-000000000001";

const listRun = vi.hoisted(() => vi.fn());
const attachRun = vi.hoisted(() => vi.fn());
vi.mock("@/context/shared/dependency-injection/infrastructure/Container", async () =>
  (await import("./_mocks")).containerMock({
    BackOfficeListBankAccountEvidence: { run: listRun },
    BackOfficeAttachBankAccountEvidence: { run: attachRun },
  }),
);

const toast = vi.hoisted(() => ({
  success: vi.fn(),
  error: vi.fn(),
  info: vi.fn(),
  warning: vi.fn(),
}));
vi.mock("@/context/shared/notification/infrastructure/Toast", () => ({ toastNotifier: toast }));

const READY: BankAccountEvidence = {
  id: "0192cccc-cccc-7ccc-8ccc-000000000001",
  documentId: "0192bbbb-bbbb-7bbb-8bbb-000000000001",
  kind: "ownership-certificate",
  attachedAt: "2026-01-01T10:00:00Z",
  documentState: "ready",
  documentName: "certificate.pdf",
  documentSizeBytes: 1536,
};
const UNNAMED: BankAccountEvidence = {
  id: "0192cccc-cccc-7ccc-8ccc-000000000002",
  documentId: "0192bbbb-bbbb-7bbb-8bbb-000000000002",
  kind: "ownership-certificate",
  attachedAt: "2026-01-02T10:00:00Z",
  documentState: "scanning",
  documentName: null,
  documentSizeBytes: null,
};

function httpError(type: string, status: number, title = "Server title"): HttpError {
  return new HttpError({ type, title, status, instance: "i", "correlation-id": "c" });
}

function pick(file: File): void {
  fireEvent.change(screen.getByTestId("bank-accounts-detail__evidence-input"), {
    target: { files: [file] },
  });
}

const pdf = new File([new Uint8Array([1, 2, 3])], "cert.pdf", { type: "application/pdf" });

function renderSection(props: { canAttach?: boolean; canDownload?: boolean } = {}) {
  return render(
    <BankAccountEvidenceSection
      accountId={ACCOUNT_ID}
      canAttach={props.canAttach ?? true}
      canDownload={props.canDownload ?? true}
    />,
  );
}

describe("BankAccountEvidenceSection", () => {
  beforeEach(() => {
    vi.clearAllMocks();
  });

  it("lists the evidence with name, state, size and a download link for a ready document only", async () => {
    listRun.mockResolvedValue([READY, UNNAMED]);
    renderSection();

    expect(
      await screen.findByTestId(`bank-accounts-detail__evidence-name-${READY.id}`),
    ).toHaveTextContent("certificate.pdf");
    expect(listRun).toHaveBeenCalledWith(ACCOUNT_ID);
    expect(
      screen.getByTestId(`bank-accounts-detail__evidence-state-${READY.id}`),
    ).toHaveTextContent("Ready");
    expect(screen.getByTestId(`bank-accounts-detail__evidence-size-${READY.id}`)).toHaveTextContent(
      "1.5 KB",
    );
    const download = screen.getByTestId(`bank-accounts-detail__evidence-download-${READY.id}`);
    expect(download).toHaveAttribute("href", `/api/v1/documents/${READY.documentId}/content`);
    expect(download).toHaveAttribute("download");

    expect(
      screen.getByTestId(`bank-accounts-detail__evidence-name-${UNNAMED.id}`),
    ).toHaveTextContent("Unnamed document");
    expect(
      screen.getByTestId(`bank-accounts-detail__evidence-state-${UNNAMED.id}`),
    ).toHaveTextContent("Scanning");
    expect(
      screen.getByTestId(`bank-accounts-detail__evidence-size-${UNNAMED.id}`),
    ).toHaveTextContent("—");
    expect(
      screen.queryByTestId(`bank-accounts-detail__evidence-download-${UNNAMED.id}`),
    ).not.toBeInTheDocument();
  });

  it("offers no download without the download permission", async () => {
    listRun.mockResolvedValue([READY]);
    renderSection({ canDownload: false });

    await screen.findByTestId(`bank-accounts-detail__evidence-name-${READY.id}`);
    expect(
      screen.queryByTestId(`bank-accounts-detail__evidence-download-${READY.id}`),
    ).not.toBeInTheDocument();
  });

  it("shows the empty state", async () => {
    listRun.mockResolvedValue([]);
    renderSection();
    expect(await screen.findByTestId("bank-accounts-detail__evidence-empty")).toHaveTextContent(
      "No evidence attached yet.",
    );
  });

  it("answers a 403 with an unobtrusive no-access line", async () => {
    listRun.mockRejectedValue(httpError("forbidden", 403));
    renderSection();
    expect(await screen.findByTestId("bank-accounts-detail__evidence-forbidden")).toHaveTextContent(
      "You don't have access to this account's evidence.",
    );
  });

  it("tells the operator the original is kept unchanged, and hides the control without the permission", async () => {
    listRun.mockResolvedValue([]);
    const { unmount } = renderSection();
    expect(await screen.findByTestId("bank-accounts-detail__evidence-help")).toHaveTextContent(
      /kept unchanged, byte for byte.*never converted or re-encoded/,
    );
    unmount();

    renderSection({ canAttach: false });
    await screen.findByTestId("bank-accounts-detail__evidence-empty");
    expect(screen.queryByTestId("bank-accounts-detail__evidence-attach")).not.toBeInTheDocument();
  });

  it("uploads with progress, attaches as an ownership certificate and refreshes the list", async () => {
    listRun.mockResolvedValueOnce([]).mockResolvedValueOnce([READY]);
    let report: ((p: { loaded: number; total: number }) => void) | undefined;
    let finish: (() => void) | undefined;
    attachRun.mockImplementation(
      (_id: string, _file: File, _kind: string, onProgress: typeof report) =>
        new Promise<void>((resolve) => {
          report = onProgress;
          finish = resolve;
        }),
    );
    renderSection();
    await screen.findByTestId("bank-accounts-detail__evidence-empty");

    pick(pdf);
    await waitFor(() => expect(attachRun).toHaveBeenCalled());
    expect(attachRun).toHaveBeenCalledWith(
      ACCOUNT_ID,
      pdf,
      "ownership-certificate",
      expect.any(Function),
    );

    report?.({ loaded: 1, total: 4 });
    const progress = await screen.findByTestId("bank-accounts-detail__evidence-progress");
    expect(progress.tagName).toBe("PROGRESS");
    expect(progress).toHaveAttribute("aria-label", "Upload progress");
    expect(progress).toHaveAttribute("value", "25");

    finish?.();
    expect(
      await screen.findByTestId(`bank-accounts-detail__evidence-name-${READY.id}`),
    ).toBeVisible();
    expect(toast.success).toHaveBeenCalledWith("Evidence attached", { description: "cert.pdf" });
  });

  it("refuses an empty file before anything is sent", async () => {
    listRun.mockResolvedValue([]);
    renderSection();
    await screen.findByTestId("bank-accounts-detail__evidence-empty");

    pick(new File([], "empty.pdf"));
    expect(await screen.findByTestId("bank-accounts-detail__evidence-refusal")).toHaveTextContent(
      /empty/,
    );
    expect(attachRun).not.toHaveBeenCalled();
  });

  it("maps a 422 attach refusal to English in a persistent, focused error surface", async () => {
    listRun.mockResolvedValue([]);
    attachRun.mockRejectedValue(
      new EvidenceAttachFailure(
        "attach",
        httpError("bank-account-evidence-retention-mismatch", 422),
      ),
    );
    renderSection();
    await screen.findByTestId("bank-accounts-detail__evidence-empty");

    pick(pdf);
    const surface = await screen.findByTestId("bank-accounts-detail__evidence-attach-error");
    expect(surface).toHaveTextContent(/different retention terms/);
    expect(surface).toHaveTextContent(/"cert.pdf" was kept as a document but is not attached/);
    expect(surface).not.toHaveTextContent("Server title");
    expect(surface).toHaveFocus();
  });

  it("offers a retry after a failed upload", async () => {
    listRun.mockResolvedValue([]);
    attachRun
      .mockRejectedValueOnce(new EvidenceAttachFailure("upload", httpError("aborted-upload", 422)))
      .mockResolvedValueOnce(READY);
    renderSection();
    await screen.findByTestId("bank-accounts-detail__evidence-empty");

    pick(pdf);
    fireEvent.click(await screen.findByTestId("bank-accounts-detail__evidence-retry-upload"));

    await waitFor(() => expect(attachRun).toHaveBeenCalledTimes(2));
    expect(attachRun.mock.calls[1][1]).toBe(pdf);
  });
});
