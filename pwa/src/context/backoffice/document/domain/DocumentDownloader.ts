/** The container key the {@link DocumentDownloader} port is bound under. */
export const DOCUMENT_DOWNLOADER_KEY = "BackOfficeDocumentDownloader";

/**
 * Port for reading the original of a `ready` document through the audited Documents read route,
 * byte for byte. A refusal (`gone`, `document-not-readable`, `forbidden`, …) precedes the first
 * byte and surfaces as the transport's `HttpError`, so nothing is saved for a document that cannot
 * be read.
 */
export interface DocumentDownloader {
  download(documentId: string): Promise<Blob>;
}
