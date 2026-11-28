/** The container key the {@link DocumentUploader} port is bound under. */
export const DOCUMENT_UPLOADER_KEY = "BackOfficeDocumentUploader";

/**
 * The retention terms an original is filed under. The CONSUMER fixes them per kind of evidence and
 * never asks the user: the API verifies on the attach that a document was filed under exactly its
 * kind's terms, so a wrong pair here is a refused attach, not a silent misfiling.
 */
export interface RetentionTerms {
  readonly retentionReason: string;
  readonly legalBasis: string;
}

/** How many bytes of the body have left the browser, as the upload reports them. */
export interface UploadProgress {
  readonly loaded: number;
  readonly total: number;
}

/** What the server filed: the document, its state, and the digest and size it computed itself. */
export interface FiledDocument {
  readonly id: string;
  readonly state: string;
  readonly digest: string;
  readonly sizeBytes: number;
}

export interface UploadOptions {
  readonly onProgress?: (progress: UploadProgress) => void;
  /** Aborting it stops the body mid-way; the upload rejects with an `AbortError` `DOMException`. */
  readonly signal?: AbortSignal;
}

/**
 * Port for filing an evidence-class original through the Documents two-step upload: the declare,
 * then the raw body. The original is never read into memory, transformed or re-encoded — the bytes
 * of the file are the bytes the server digests. Every server refusal surfaces as the transport's
 * `HttpError`, read by its Problem Details `type`.
 */
export interface DocumentUploader {
  upload(file: File, terms: RetentionTerms, options?: UploadOptions): Promise<FiledDocument>;
}
