import type { RetentionTerms } from "./RetentionTerms";

/** The container key the {@link DocumentUploader} port is bound under. */
export const DOCUMENT_UPLOADER_KEY = "DocumentUploader";

/**
 * What a successful upload answers: the document filed, its state (`received` until the scan
 * decides), and the digest and size the server computed.
 */
export interface FiledDocument {
  readonly id: string;
  readonly state: string;
  readonly digest: string;
  readonly sizeBytes: number;
}

/** Bytes of the original sent so far, out of its size. */
export interface DocumentUploadProgress {
  readonly loaded: number;
  readonly total: number;
}

/**
 * What an upload reports while it runs. `onSending` fires once the declare is answered and the body
 * starts — from then on a failure may leave a filed document behind, which is why a caller needs to
 * know it. `onSent` fires once the last byte has left the browser, while the server is still filing.
 */
export interface DocumentUploadObserver {
  readonly onSending?: () => void;
  readonly onProgress?: (progress: DocumentUploadProgress) => void;
  readonly onSent?: () => void;
}

/**
 * Port for filing an original as evidence, kept byte for byte (never re-encoded, unlike an image).
 * One call covers the API's two steps — declare the size and retention terms, then send the body
 * with the original filename — because neither is meaningful alone to a caller.
 *
 * Every rejection surfaces as the transport's `HttpError`; callers read the Problem Details `type`
 * (see {@link import("./documentUploadProblemMessage").documentUploadProblemMessage}).
 */
export interface DocumentUploader {
  upload(
    file: File,
    terms: RetentionTerms,
    observer?: DocumentUploadObserver,
  ): Promise<FiledDocument>;
}
