import { afterEach, beforeEach, describe, expect, it, vi } from "vitest";
import { saveBlob } from "@/context/backoffice/document/infrastructure/saveBlob";

const OBJECT_URL = "blob:https://localhost/1";

describe("saveBlob", () => {
  beforeEach(() => {
    vi.useFakeTimers();
    URL.createObjectURL = vi.fn(() => OBJECT_URL);
    URL.revokeObjectURL = vi.fn();
  });

  afterEach(() => {
    vi.useRealTimers();
    vi.restoreAllMocks();
  });

  it("clicks an anchor attached to the document, removes it, and revokes the URL a minute later", () => {
    const seen: { connected: boolean; download: string; href: string }[] = [];
    vi.spyOn(HTMLAnchorElement.prototype, "click").mockImplementation(function (
      this: HTMLAnchorElement,
    ) {
      seen.push({ connected: this.isConnected, download: this.download, href: this.href });
    });

    saveBlob(new Blob(["%PDF"]), "certificate.pdf");

    expect(seen).toEqual([{ connected: true, download: "certificate.pdf", href: OBJECT_URL }]);
    expect(document.querySelector("a[download]")).toBeNull();
    expect(URL.revokeObjectURL).not.toHaveBeenCalled();

    vi.advanceTimersByTime(59_999);
    expect(URL.revokeObjectURL).not.toHaveBeenCalled();
    vi.advanceTimersByTime(1);
    expect(URL.revokeObjectURL).toHaveBeenCalledWith(OBJECT_URL);
  });
});
