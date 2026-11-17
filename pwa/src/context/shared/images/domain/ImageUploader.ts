/** The container key the {@link ImageUploader} port is bound under. */
export const IMAGE_UPLOADER_KEY = "ImageUploader";

/** What a successful write answers: the id the owner now holds. */
export interface UploadedImage {
  readonly imageId: string;
}

/**
 * Port for writing and removing the single image a consumer holds. The consumer owns the route
 * (there is no generic upload route), so each call carries the write URL of the owner's image —
 * a bank's logo, a user's avatar — and the capability owns only the transport shape: raw bytes
 * out, `{ imageId }` back.
 *
 * Every rejection surfaces as the transport's `HttpError`; callers read the Problem Details
 * `type` (see {@link imageProblemMessage}) and never a status code.
 */
export interface ImageUploader {
  /** `PUT` the file's bytes as the owner's image. Replaces any image already held. */
  upload(url: string, file: Blob): Promise<UploadedImage>;
  /** `DELETE` the owner's image. Idempotent: holding none answers the same success. */
  remove(url: string): Promise<void>;
}
