import { beforeEach, describe, expect, it, vi } from "vitest";
import { act, fireEvent, render, screen, waitFor } from "@testing-library/react";
import { BankAccountEvidenceSection } from "@/app/backoffice/bank-accounts/[id]/_components/BankAccountEvidenceSection";
import {
  EvidenceAttachFailure,
  type EvidenceAttachObserver,
} from "@/context/backoffice/bankaccount/application/AttachBankAccountEvidence";
import type { BankAccountEvidence } from "@/context/backoffice/bankaccount/domain/BankAccountEvidence";
import { HttpError } from "@/context/shared/http-client/domain/HttpError";

const ACCOUNT_ID = "0192aaaa-aaaa-7aaa-8aaa-000000000001";
const DOCUMENT_ID = "0192bbbb-bbbb-7bbb-8bbb-000000000009";

const listRun = vi.hoisted(() => vi.fn());
const attachRun = vi.hoisted(() => vi.fn());
const retryAttach = vi.hoisted(() => vi.fn());
vi.mock("@/context/shared/dependency-injection/infrastructure/Container", async () =>
  (await import("./_mocks")).containerMock({
    BackOfficeListBankAccountEvidence: { run: listRun },
    BackOfficeAttachBankAccountEvidence: { run: attachRun, retryAttach },
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

  it("labels each stage from the use case: uploading with progress, finishing, then attaching", async () => {
    listRun.mockResolvedValueOnce([]).mockResolvedValueOnce([READY]);
    let observer: EvidenceAttachObserver | undefined;
    let finish: (() => void) | undefined;
    attachRun.mockImplementation(
      (_id: string, _file: File, _kind: string, given: EvidenceAttachObserver) =>
        new Promise<void>((resolve) => {
          observer = given;
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
      expect.any(Object),
    );

    act(() => {
      observer?.onStage?.("upload");
      observer?.onProgress?.({ loaded: 1, total: 4 });
    });
    const progress = await screen.findByTestId("bank-accounts-detail__evidence-progress");
    expect(progress.tagName).toBe("PROGRESS");
    expect(progress).toHaveAttribute("aria-label", "Upload progress");
    expect(progress).toHaveAttribute("value", "25");

    // The last byte has left: no inference from the figures, the use case says so.
    act(() => observer?.onProgress?.({ loaded: 4, total: 4 }));
    expect(
      screen.queryByTestId("bank-accounts-detail__evidence-finishing"),
    ).not.toBeInTheDocument();
    act(() => observer?.onStage?.("finishing"));
    expect(screen.getByTestId("bank-accounts-detail__evidence-finishing")).toHaveTextContent(
      "Finishing upload…",
    );
    expect(
      screen.queryByTestId("bank-accounts-detail__evidence-attaching"),
    ).not.toBeInTheDocument();

    act(() => observer?.onStage?.("attach"));
    expect(screen.getByTestId("bank-accounts-detail__evidence-attaching")).toHaveTextContent(
      "Attaching…",
    );
    expect(
      screen.queryByTestId("bank-accounts-detail__evidence-finishing"),
    ).not.toBeInTheDocument();

    act(() => finish?.());
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

  it("refuses a file name the server would refuse before anything is sent", async () => {
    listRun.mockResolvedValue([]);
    renderSection();
    await screen.findByTestId("bank-accounts-detail__evidence-empty");

    pick(new File([new Uint8Array([1])], "invoice\u202Efdp.exe"));
    expect(await screen.findByTestId("bank-accounts-detail__evidence-refusal")).toHaveTextContent(
      /Rename the file/,
    );
    expect(attachRun).not.toHaveBeenCalled();
  });

  it("maps a non-retryable attach refusal to English, focused, with no retry", async () => {
    listRun.mockResolvedValue([]);
    attachRun.mockRejectedValue(
      new EvidenceAttachFailure(
        "attach",
        httpError("bank-account-evidence-retention-mismatch", 422),
        true,
        DOCUMENT_ID,
      ),
    );
    renderSection();
    await screen.findByTestId("bank-accounts-detail__evidence-empty");

    pick(pdf);
    const surface = await screen.findByTestId("bank-accounts-detail__evidence-attach-error");
    expect(surface).toHaveTextContent(/different retention terms/);
    expect(surface).toHaveTextContent(
      /"cert.pdf" was filed as a document but is not attached to the account. An administrator can find it in the documents listing./,
    );
    expect(surface).not.toHaveTextContent("Server title");
    expect(surface).toHaveFocus();
    expect(
      screen.queryByTestId("bank-accounts-detail__evidence-retry-attach"),
    ).not.toBeInTheDocument();
    expect(
      screen.queryByTestId("bank-accounts-detail__evidence-retry-upload"),
    ).not.toBeInTheDocument();
  });

  it("retries a transient attach failure with the same document and no upload, then refreshes", async () => {
    listRun.mockResolvedValue([]);
    attachRun.mockRejectedValue(
      new EvidenceAttachFailure("attach", httpError("rate-limited", 429), true, DOCUMENT_ID),
    );
    retryAttach.mockResolvedValue(READY);
    renderSection();
    await screen.findByTestId("bank-accounts-detail__evidence-empty");

    pick(pdf);
    expect(
      await screen.findByTestId("bank-accounts-detail__evidence-attach-error"),
    ).toHaveTextContent(/will not upload it twice/);
    listRun.mockResolvedValue([READY]);
    fireEvent.click(screen.getByTestId("bank-accounts-detail__evidence-retry-attach"));

    await waitFor(() =>
      expect(retryAttach).toHaveBeenCalledWith(ACCOUNT_ID, DOCUMENT_ID, "ownership-certificate"),
    );
    expect(attachRun).toHaveBeenCalledTimes(1);
    expect(
      await screen.findByTestId(`bank-accounts-detail__evidence-name-${READY.id}`),
    ).toBeVisible();
    expect(
      screen.queryByTestId("bank-accounts-detail__evidence-attach-error"),
    ).not.toBeInTheDocument();
  });

  it("does not claim an attach of unknown outcome failed, and reloads the list", async () => {
    listRun.mockResolvedValue([]);
    attachRun.mockRejectedValue(
      new EvidenceAttachFailure("attach", httpError("request-timeout", 0), true, DOCUMENT_ID),
    );
    renderSection();
    await screen.findByTestId("bank-accounts-detail__evidence-empty");

    pick(pdf);
    const surface = await screen.findByTestId("bank-accounts-detail__evidence-attach-error");
    expect(surface).toHaveTextContent(/it is not known whether it was attached/);
    expect(surface).not.toHaveTextContent(/is not attached/);
    await waitFor(() => expect(listRun).toHaveBeenCalledTimes(2));
    expect(screen.getByTestId("bank-accounts-detail__evidence-retry-attach")).toBeVisible();
  });

  it("offers no blind re-upload when the upload's outcome is unknown, and says where the file may be", async () => {
    listRun.mockResolvedValue([]);
    attachRun.mockRejectedValue(
      new EvidenceAttachFailure("upload", httpError("about:blank", 504), true, null),
    );
    renderSection();
    await screen.findByTestId("bank-accounts-detail__evidence-empty");

    pick(pdf);
    const surface = await screen.findByTestId("bank-accounts-detail__evidence-attach-error");
    expect(surface).toHaveTextContent(
      /may have been filed without being attached to this account. An administrator can find it in the documents listing./,
    );
    expect(surface).not.toHaveTextContent(/refresh the list/i);
    expect(
      screen.queryByTestId("bank-accounts-detail__evidence-retry-upload"),
    ).not.toBeInTheDocument();
  });

  it("offers a re-upload only after a retryable upload failure", async () => {
    listRun.mockResolvedValue([]);
    attachRun
      .mockRejectedValueOnce(
        new EvidenceAttachFailure("upload", httpError("aborted-upload", 422), true, null),
      )
      .mockResolvedValueOnce(READY);
    renderSection();
    await screen.findByTestId("bank-accounts-detail__evidence-empty");

    pick(pdf);
    fireEvent.click(await screen.findByTestId("bank-accounts-detail__evidence-retry-upload"));

    await waitFor(() => expect(attachRun).toHaveBeenCalledTimes(2));
    expect(attachRun.mock.calls[1][1]).toBe(pdf);
  });

  it("offers no re-upload after a refusal that would only repeat", async () => {
    listRun.mockResolvedValue([]);
    attachRun.mockRejectedValue(
      new EvidenceAttachFailure("upload", httpError("forbidden", 403), false, null),
    );
    renderSection();
    await screen.findByTestId("bank-accounts-detail__evidence-empty");

    pick(pdf);
    await screen.findByTestId("bank-accounts-detail__evidence-attach-error");
    expect(
      screen.queryByTestId("bank-accounts-detail__evidence-retry-upload"),
    ).not.toBeInTheDocument();
  });

  it("refreshes the list on demand, so a scanned document becomes downloadable", async () => {
    listRun
      .mockResolvedValueOnce([UNNAMED])
      .mockResolvedValueOnce([{ ...UNNAMED, documentState: "ready" }]);
    renderSection();
    await screen.findByTestId(`bank-accounts-detail__evidence-name-${UNNAMED.id}`);
    expect(
      screen.queryByTestId(`bank-accounts-detail__evidence-download-${UNNAMED.id}`),
    ).not.toBeInTheDocument();

    const refresh = screen.getByTestId("bank-accounts-detail__evidence-refresh");
    expect(refresh).toHaveAttribute("aria-label", "Refresh");
    expect(refresh).toHaveAttribute("title", "Refresh the evidence list");
    fireEvent.click(refresh);

    expect(
      await screen.findByTestId(`bank-accounts-detail__evidence-download-${UNNAMED.id}`),
    ).toBeVisible();
  });

  it("drops an older list answer that resolves after a newer one", async () => {
    let resolveSlow: ((items: BankAccountEvidence[]) => void) | undefined;
    listRun
      .mockResolvedValueOnce([]) // the mount's load
      .mockImplementationOnce(
        () =>
          new Promise<BankAccountEvidence[]>((resolve) => {
            resolveSlow = resolve;
          }),
      ) // the older refresh: still pending
      .mockResolvedValueOnce([READY]); // the newer refresh: answers at once
    renderSection();
    const refresh = await screen.findByTestId("bank-accounts-detail__evidence-refresh");

    fireEvent.click(refresh);
    fireEvent.click(refresh);
    expect(
      await screen.findByTestId(`bank-accounts-detail__evidence-name-${READY.id}`),
    ).toBeVisible();

    await act(async () => resolveSlow?.([UNNAMED]));
    expect(listRun).toHaveBeenCalledTimes(3);
    expect(screen.getByTestId(`bank-accounts-detail__evidence-name-${READY.id}`)).toBeVisible();
    expect(
      screen.queryByTestId(`bank-accounts-detail__evidence-name-${UNNAMED.id}`),
    ).not.toBeInTheDocument();
  });

  it("does not claim an attach failed when the server answered success unreadably", async () => {
    listRun.mockResolvedValue([]);
    attachRun.mockRejectedValue(
      new EvidenceAttachFailure(
        "attach",
        httpError("malformed-response-envelope", 201),
        true,
        DOCUMENT_ID,
      ),
    );
    renderSection();
    await screen.findByTestId("bank-accounts-detail__evidence-empty");

    pick(pdf);
    const surface = await screen.findByTestId("bank-accounts-detail__evidence-attach-error");
    expect(surface).toHaveTextContent(/it is not known whether it was attached/);
    expect(surface).not.toHaveTextContent(/is not attached/);
  });

  it("does not claim an upload kept nothing when the server answered success unreadably", async () => {
    listRun.mockResolvedValue([]);
    attachRun.mockRejectedValue(
      new EvidenceAttachFailure(
        "upload",
        httpError("malformed-response-envelope", 201),
        true,
        null,
      ),
    );
    renderSection();
    await screen.findByTestId("bank-accounts-detail__evidence-empty");

    pick(pdf);
    const surface = await screen.findByTestId("bank-accounts-detail__evidence-attach-error");
    expect(surface).toHaveTextContent(/may have been filed without being attached/);
    expect(surface).not.toHaveTextContent(/was not kept/);
    expect(
      screen.queryByTestId("bank-accounts-detail__evidence-retry-upload"),
    ).not.toBeInTheDocument();
  });

  it("names the download only from an allowlisted file name", async () => {
    const odd = {
      ...READY,
      id: "0192cccc-cccc-7ccc-8ccc-000000000003",
      documentName: "../evil.pdf",
    };
    listRun.mockResolvedValue([READY, odd]);
    renderSection();

    expect(
      await screen.findByTestId(`bank-accounts-detail__evidence-download-${READY.id}`),
    ).toHaveAttribute("download", "certificate.pdf");
    expect(screen.getByTestId(`bank-accounts-detail__evidence-download-${odd.id}`)).toHaveAttribute(
      "download",
      "",
    );
  });

  it("renders a kind and a state this client does not know as unknown", async () => {
    listRun.mockResolvedValue([{ ...READY, kind: null, documentState: null }]);
    renderSection();

    const row = await screen.findByTestId(`bank-accounts-detail__evidence-item-${READY.id}`);
    expect(row).toHaveTextContent("Unknown kind");
    expect(
      screen.getByTestId(`bank-accounts-detail__evidence-state-${READY.id}`),
    ).toHaveTextContent("Unknown");
  });
});
