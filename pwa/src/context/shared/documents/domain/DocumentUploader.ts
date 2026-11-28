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
    onProgress?: (progress: DocumentUploadProgress) => void,
  ): Promise<FiledDocument>;
}
