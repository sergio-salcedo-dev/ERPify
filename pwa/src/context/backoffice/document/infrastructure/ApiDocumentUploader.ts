import { inject, injectable } from "inversify";
import type { HttpClient, ResponseGuard } from "@/context/shared/http-client/domain/HttpClient";
import { MALFORMED_RESPONSE_ENVELOPE } from "@/context/shared/http-client/domain/HttpClient";
import { HttpError } from "@/context/shared/http-client/domain/HttpError";
import { API_ENDPOINTS } from "@/context/shared/http-client/infrastructure/ApiEndpoints";
import { uuidV7 } from "@/context/shared/uuid/infrastructure/uuidV7";
import { normalisedFilename } from "../domain/checkDocumentFile";
import type {
  DocumentUploader,
  FiledDocument,
  RetentionTerms,
  UploadOptions,
} from "../domain/DocumentUploader";
import { documentsUrl, problemFrom, unreachable } from "./documentsTransport";

/**
 * The refusals the server answers before it claims the upload, so the upload is still `Declared`
 * and can take the body again: a name or a length it refused, the in-flight cap, an expired
 * session. A transfer that failed before a byte left is the same case.
 */
const PRE_CLAIM_TYPES: ReadonlySet<string> = new Set([
  "invalid-filename",
  "content-length-mismatch",
  "length-required",
  "rate-limited",
]);

/** A transfer that failed with nothing sent: the upload was never claimed. */
class UnclaimedUploadError extends HttpError {
  constructor(cause: HttpError) {
    super(cause.problem);
    this.name = "HttpError";
  }
}

function refusedBeforeTheClaim(error: unknown): boolean {
  if (error instanceof UnclaimedUploadError) return true;
  if (!(error instanceof HttpError)) return false;
  return error.problem.status === 401 || PRE_CLAIM_TYPES.has(error.problem.type);
}

interface DeclaredUpload {
  data: { id: string };
}

const isDeclaredUpload: ResponseGuard<DeclaredUpload> = (body): body is DeclaredUpload => {
  if (typeof body !== "object" || body === null) return false;
  const { data } = body as { data?: unknown };
  if (typeof data !== "object" || data === null) return false;
  const { id } = data as { id?: unknown };
  return typeof id === "string" && id !== "";
};

function isFiledDocument(body: unknown): body is { data: FiledDocument } {
  if (typeof body !== "object" || body === null) return false;
  const { data } = body as { data?: unknown };
  if (typeof data !== "object" || data === null) return false;
  const document = data as Record<string, unknown>;
  return (
    typeof document.id === "string" &&
    typeof document.state === "string" &&
    typeof document.digest === "string" &&
    typeof document.sizeBytes === "number"
  );
}

/**
 * The Documents two-step upload, as the browser runs it.
 *
 * Step 1 is the declare — a JSON call through `HttpClient` naming the exact size and the retention
 * terms — which answers the upload id. Step 2 PUTs the file itself to that id's content route with
 * `XMLHttpRequest`, because it is the one browser transport that reports upload progress; a
 * streaming `fetch` body cannot carry the `Content-Length` the route requires.
 *
 * What the PUT promises, each a property of the request rather than of the server:
 *  - **The original, untouched and never in memory.** The body is the `File` itself, re-typed as
 *    `application/octet-stream` by wrapping it in a `Blob` (a wrapper holds a reference, not a
 *    copy), so the browser streams it from disk and PHP never sees a form content type to parse.
 *  - **The exact `Content-Length`.** The browser sets it from the blob's size, which is the size
 *    the declare named; the route refuses any other.
 *  - **The original filename in the query only.** NFC-normalised (a macOS name arrives in NFD,
 *    which the API refuses whole) and `encodeURIComponent`-escaped in the single `filename`
 *    parameter; never in a header or the body. The access log keeps no query, and the client
 *    telemetry redacts this route's whole query (`redaction.ts`, mirroring the API).
 *  - **The session cookie.** A same-origin request carries it, which is what lets Caddy lift its
 *    body bound to 100 MiB for this route.
 *
 * Nothing times the PUT out: a large original on a slow uplink is not a hung request, and the API
 * owns the deadline. A refusal surfaces as the transport's `HttpError`; aborting the signal
 * rejects with an `AbortError` `DOMException`.
 */
@injectable()
export class ApiDocumentUploader implements DocumentUploader {
  constructor(@inject("HttpClient") private readonly httpClient: HttpClient) {}

  /**
   * Uploads the server still holds as `Declared` because their PUT was refused before it was
   * claimed, keyed by the file's size and modification time — what the declare named, since the
   * declare carries no name. A retry of the same file sends its body to that upload instead of
   * declaring again, so a refusal does not cost one of the per-actor declared slots each time.
   */
  private readonly unclaimed = new Map<string, string>();

  async upload(file: File, terms: RetentionTerms, options?: UploadOptions): Promise<FiledDocument> {
    // Everything the PUT carries is built before anything is declared: a name that cannot be
    // spelled (`encodeURIComponent` throws on a lone surrogate) refuses here, before the server
    // holds an upload for it.
    const encodedFilename = encodeURIComponent(normalisedFilename(file));
    const key = `${file.size}:${file.lastModified}`;
    const uploadId = this.unclaimed.get(key) ?? (await this.declare(file, terms));

    try {
      const filed = await this.send(uploadId, encodedFilename, file, options);
      this.unclaimed.delete(key);
      return filed;
    } catch (error) {
      if (refusedBeforeTheClaim(error)) {
        this.unclaimed.set(key, uploadId);
      } else {
        this.unclaimed.delete(key);
      }
      throw error;
    }
  }

  /** Test seam: the request object the PUT is sent with. */
  protected createRequest(): XMLHttpRequest {
    return new XMLHttpRequest();
  }

  private async declare(file: File, terms: RetentionTerms): Promise<string> {
    const declared = await this.httpClient.post(
      API_ENDPOINTS.DOCUMENTS.UPLOAD_DECLARE,
      { size: file.size, retentionReason: terms.retentionReason, legalBasis: terms.legalBasis },
      isDeclaredUpload,
    );
    return declared.data.id;
  }

  private send(
    uploadId: string,
    encodedFilename: string,
    file: File,
    options?: UploadOptions,
  ): Promise<FiledDocument> {
    const url = documentsUrl(
      `${API_ENDPOINTS.DOCUMENTS.UPLOAD_CONTENT(uploadId)}?filename=${encodedFilename}`,
    );
    const body = new Blob([file], { type: "application/octet-stream" });

    return new Promise<FiledDocument>((resolve, reject) => {
      const signal = options?.signal;
      if (signal?.aborted) {
        reject(new DOMException("The upload was cancelled.", "AbortError"));
        return;
      }
      const request = this.createRequest();
      const onAbortSignal = (): void => request.abort();

      const settle = (outcome: () => void): void => {
        signal?.removeEventListener("abort", onAbortSignal);
        outcome();
      };

      request.open("PUT", url);
      request.setRequestHeader("Accept", "application/json");
      let sent = 0;
      request.upload.onprogress = (event: ProgressEvent): void => {
        sent = event.loaded;
        options?.onProgress?.({
          loaded: event.loaded,
          total: event.lengthComputable ? event.total : file.size,
        });
      };
      request.onload = (): void => {
        settle(() => {
          const correlationId = request.getResponseHeader("X-Correlation-Id");
          if (request.status < 200 || request.status >= 300) {
            reject(problemFrom(request.status, request.responseText, correlationId));
            return;
          }
          let parsed: unknown = null;
          try {
            parsed = JSON.parse(request.responseText);
          } catch {
            parsed = null;
          }
          if (!isFiledDocument(parsed)) {
            reject(
              new HttpError({
                type: MALFORMED_RESPONSE_ENVELOPE,
                title: "API response did not match the expected shape",
                status: request.status,
                detail: "Unexpected response body shape for the evidence upload",
                instance: uuidV7(),
                "correlation-id": correlationId ?? uuidV7(),
              }),
            );
            return;
          }
          resolve(parsed.data);
        });
      };
      request.onerror = (): void =>
        settle(() => reject(sent === 0 ? new UnclaimedUploadError(unreachable()) : unreachable()));
      request.onabort = (): void =>
        settle(() => reject(new DOMException("The upload was cancelled.", "AbortError")));

      signal?.addEventListener("abort", onAbortSignal, { once: true });
      request.send(body);
    });
  }
}
