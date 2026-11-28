import { injectable } from "inversify";
import { API_ENDPOINTS } from "@/context/shared/http-client/infrastructure/ApiEndpoints";
import type { DocumentDownloader } from "../domain/DocumentDownloader";
import { documentsUrl, problemFrom, unreachable } from "./documentsTransport";

/**
 * Reads an original through the audited Documents read route as bytes. Not an `HttpClient` verb:
 * that port reads every body as text. The route refuses before its first byte, so `response.ok`
 * is checked before the body is read and a refusal becomes the transport's `HttpError`, never a
 * saved file holding a Problem Details body. Same-origin, so the session cookie rides along; no
 * timeout, because a large original on a slow link is not a hung request.
 *
 * The whole original is held as one `Blob` until it is saved — up to 100 MiB in the tab.
 */
@injectable()
export class ApiDocumentDownloader implements DocumentDownloader {
  async download(documentId: string): Promise<Blob> {
    let response: Response;
    try {
      response = await fetch(documentsUrl(API_ENDPOINTS.DOCUMENTS.CONTENT(documentId)), {
        method: "GET",
        credentials: "same-origin",
        cache: "no-store",
      });
    } catch {
      throw unreachable();
    }
    if (!response.ok) {
      throw problemFrom(
        response.status,
        await response.text(),
        response.headers.get("X-Correlation-Id"),
      );
    }
    try {
      return await response.blob();
    } catch {
      throw unreachable();
    }
  }
}
