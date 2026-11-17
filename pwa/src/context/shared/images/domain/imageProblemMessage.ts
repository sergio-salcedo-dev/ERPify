import {
  retryAfterSecondsOf,
  type ProblemDetails,
} from "@/context/shared/error/domain/ProblemDetails";
import { NETWORK_ERROR, REQUEST_TIMEOUT } from "@/context/shared/http-client/domain/HttpClient";
import { HttpStatus } from "@/context/shared/http-client/domain/HttpStatus";
import { MAX_IMAGE_MEGABYTES } from "./imageLimits";
import { ImageProblemType } from "./ImageProblemType";

const INVALID_UUID = "invalid-uuid";

const IMAGE_TYPE_MESSAGE: Record<ImageProblemType, string> = {
  [ImageProblemType.LENGTH_REQUIRED]:
    "The upload was cut short or its size could not be confirmed, so nothing was stored. Try again.",
  [ImageProblemType.TOO_LARGE]: `This image is too large. Choose one under ${MAX_IMAGE_MEGABYTES} MB and with smaller dimensions.`,
  [ImageProblemType.UNSUPPORTED_TYPE]:
    "This file is not a supported image format, or it does not match its file type. Use a genuine JPEG, PNG, WebP or GIF image.",
  [ImageProblemType.INVALID]: "This file could not be read as an image. Choose a different image.",
};

const CLIENT_TYPE_MESSAGE: ReadonlyMap<string, string> = new Map([
  [NETWORK_ERROR, "Could not reach the server. Check your connection and try again."],
  [
    REQUEST_TIMEOUT,
    "The server did not answer in time, so the change may not have been saved. Try again.",
  ],
  [INVALID_UUID, "The item this image belongs to could not be identified. Reload the page."],
]);

/**
 * The image refusals the API also answers by status alone when a proxy or the edge matcher
 * refuses before the application can mint its own `type` — those arrive as `about:blank`.
 */
const IMAGE_TYPE_BY_STATUS: Readonly<Record<number, ImageProblemType>> = {
  [HttpStatus.LENGTH_REQUIRED]: ImageProblemType.LENGTH_REQUIRED,
  [HttpStatus.CONTENT_TOO_LARGE]: ImageProblemType.TOO_LARGE,
  [HttpStatus.UNSUPPORTED_MEDIA_TYPE]: ImageProblemType.UNSUPPORTED_TYPE,
  [HttpStatus.UNPROCESSABLE_ENTITY]: ImageProblemType.INVALID,
};

const STATUS_MESSAGE: Record<number, string> = {
  [HttpStatus.UNAUTHORIZED]: "Your session has expired. Sign in again to continue.",
  [HttpStatus.FORBIDDEN]: "You do not have permission to change this image.",
  [HttpStatus.NOT_FOUND]: "The item this image belongs to no longer exists. Reload the page.",
};

export const ImageAction = {
  SAVE: "save",
  REMOVE: "remove",
} as const;

export type ImageAction = (typeof ImageAction)[keyof typeof ImageAction];

const FALLBACK_MESSAGE: Record<ImageAction, string> = {
  [ImageAction.SAVE]: "The image could not be saved. Try again.",
  [ImageAction.REMOVE]: "The image could not be removed. Try again.",
};

function throttledMessage(problem: ProblemDetails): string {
  const seconds = retryAfterSecondsOf(problem);
  if (seconds === null)
    return "Too many image changes in a short time. Wait a moment and try again.";
  const unit = seconds === 1 ? "second" : "seconds";
  return `Too many image changes in a short time. Wait ${seconds} ${unit} and try again.`;
}

function isImageProblemType(type: string): type is ImageProblemType {
  return (Object.values(ImageProblemType) as string[]).includes(type);
}

/**
 * The English copy for a failed image write or removal. The image types speak for themselves and
 * are routed on `type`; the outcomes that reuse a type other routes also mint (401, 403, 404,
 * 429) are routed on status, because the owner's not-found `type` differs per consumer and a
 * list of them would be a copy of every consumer's constants. A refusal with no image `type`
 * (an edge or proxy answer, `about:blank`) still gets its image message when its status is one
 * the image routes mint. `action` only picks the fallback's verb.
 */
export function imageProblemMessage(
  problem: ProblemDetails,
  action: ImageAction = ImageAction.SAVE,
): string {
  if (isImageProblemType(problem.type)) return IMAGE_TYPE_MESSAGE[problem.type];
  const byType = CLIENT_TYPE_MESSAGE.get(problem.type);
  if (byType !== undefined) return byType;
  if (problem.status === HttpStatus.TOO_MANY_REQUESTS) return throttledMessage(problem);
  const byStatus = STATUS_MESSAGE[problem.status];
  if (byStatus !== undefined) return byStatus;
  const imageType = IMAGE_TYPE_BY_STATUS[problem.status];
  return imageType === undefined ? FALLBACK_MESSAGE[action] : IMAGE_TYPE_MESSAGE[imageType];
}
