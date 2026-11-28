import { inject, injectable } from "inversify";
import {
  DOCUMENT_DOWNLOADER_KEY,
  type DocumentDownloader,
} from "@/context/backoffice/document/domain/DocumentDownloader";
import type { BankAccountEvidence } from "../domain/BankAccountEvidence";

/** The fallback name of a saved original whose own name did not open. */
const FALLBACK_FILENAME = "document";

/**
 * The name a saved original gets: its own, reduced to one path segment with no control
 * characters — the browser applies its own sanitisation to a `download` name too, this only keeps
 * a hostile name from depending on it.
 */
export function savedFilename(evidence: Pick<BankAccountEvidence, "name">): string {
  const name = Array.from(evidence.name ?? "", (character) => {
    const code = character.codePointAt(0) ?? 0;
    return code < 0x20 || code === 0x7f || character === "/" || character === "\\"
      ? "_"
      : character;
  })
    .join("")
    .trim();
  return name === "" || name === "." || name === ".." ? FALLBACK_FILENAME : name;
}

export interface DownloadedEvidence {
  readonly blob: Blob;
  readonly filename: string;
}

@injectable()
export class DownloadBankAccountEvidence {
  constructor(@inject(DOCUMENT_DOWNLOADER_KEY) private readonly downloader: DocumentDownloader) {}

  async run(
    evidence: Pick<BankAccountEvidence, "documentId" | "name">,
  ): Promise<DownloadedEvidence> {
    const blob = await this.downloader.download(evidence.documentId);
    return { blob, filename: savedFilename(evidence) };
  }
}
