import { inject, injectable } from "inversify";
import type { HttpClient, ResponseGuard } from "@/context/shared/http-client/domain/HttpClient";
import { API_ENDPOINTS } from "@/context/shared/http-client/infrastructure/ApiEndpoints";
import type {
  DocumentUploader,
  DocumentUploadProgress,
  FiledDocument,
} from "../domain/DocumentUploader";
import type { RetentionTerms } from "../domain/RetentionTerms";
import { uploadBudgetMs } from "../domain/documentLimits";

interface DeclaredUpload {
  data: { id: string };
}

interface FiledDocumentResponse {
  data: FiledDocument;
}

function isObjectRecord(value: unknown): value is Record<string, unknown> {
  return typeof value === "object" && value !== null;
}

// The declare answers `id: null` only for a replayed idempotency key whose upload is retired; this
// adapter sends no key, so an upload id is required and anything else is a malformed envelope.
const isDeclaredUpload: ResponseGuard<DeclaredUpload> = (body): body is DeclaredUpload =>
  isObjectRecord(body) &&
  isObjectRecord(body.data) &&
  typeof body.data.id === "string" &&
  body.data.id !== "";

const isFiledDocumentResponse: ResponseGuard<FiledDocumentResponse> = (
  body,
): body is FiledDocumentResponse =>
  isObjectRecord(body) &&
  isObjectRecord(body.data) &&
  typeof body.data.id === "string" &&
  typeof body.data.state === "string" &&
  typeof body.data.digest === "string" &&
  typeof body.data.sizeBytes === "number";

/**
 * HTTP adapter over the two-step evidence upload: `POST /documents/uploads` declares the size and
 * the retention terms (no filename, ever), then `PUT …/{id}/content?filename=…` sends the File
 * itself as the body. The File is handed to the transport as-is, never read into memory, so the
 * browser streams it and sets `Content-Length` from its size — which is the declared size by
 * construction. No status is interpreted here: a refusal reaches the caller as the transport's
 * `HttpError`.
 */
@injectable()
export class ApiDocumentUploader implements DocumentUploader {
  constructor(@inject("HttpClient") private readonly httpClient: HttpClient) {}

  async upload(
    file: File,
    terms: RetentionTerms,
    onProgress?: (progress: DocumentUploadProgress) => void,
  ): Promise<FiledDocument> {
    const declared = await this.httpClient.post(
      API_ENDPOINTS.DOCUMENTS.UPLOAD_DECLARE,
      {
        size: file.size,
        retentionReason: terms.retentionReason,
        legalBasis: terms.legalBasis,
        reviewAt: terms.reviewAt,
      },
      isDeclaredUpload,
    );

    const filed = await this.httpClient.putRaw(
      `${API_ENDPOINTS.DOCUMENTS.UPLOAD_CONTENT(declared.data.id)}?filename=${encodeURIComponent(file.name)}`,
      file,
      isFiledDocumentResponse,
      {
        // The body is the original whatever its type; the browser's guess from the extension is
        // not something the server reads.
        headers: { "Content-Type": "application/octet-stream" },
        timeoutMs: uploadBudgetMs(file.size),
        onUploadProgress: onProgress,
      },
    );
    return filed.data;
  }
}
