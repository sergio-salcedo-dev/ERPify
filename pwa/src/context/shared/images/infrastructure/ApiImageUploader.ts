import { inject, injectable } from "inversify";
import type { HttpClient, ResponseGuard } from "@/context/shared/http-client/domain/HttpClient";
import type { ImageUploader, UploadedImage } from "../domain/ImageUploader";

/**
 * The default 30 s budget is sized for a JSON round trip. An image is up to 20 MiB, and the same
 * budget would give up on a slow uplink mid-body and report an outage that was only a large file.
 */
const UPLOAD_TIMEOUT_MS = 120_000;

const isUploadedImage: ResponseGuard<UploadedImage> = (body): body is UploadedImage => {
  if (typeof body !== "object" || body === null) return false;
  const { imageId } = body as { imageId?: unknown };
  return typeof imageId === "string" && imageId.trim() !== "";
};

/**
 * HTTP adapter over the consumer-owned image write routes: `PUT` raw bytes answers `200
 * { imageId }`, `DELETE` answers `204`. No status is interpreted here — a refusal reaches the
 * caller as the transport's `HttpError`, and a 2xx body that is not `{ imageId }` becomes a
 * `malformed-response-envelope` rather than an owner holding `undefined`.
 */
@injectable()
export class ApiImageUploader implements ImageUploader {
  constructor(@inject("HttpClient") private readonly httpClient: HttpClient) {}

  upload(url: string, file: Blob): Promise<UploadedImage> {
    return this.httpClient.putRaw(url, file, isUploadedImage, { timeoutMs: UPLOAD_TIMEOUT_MS });
  }

  async remove(url: string): Promise<void> {
    await this.httpClient.delete(url);
  }
}
