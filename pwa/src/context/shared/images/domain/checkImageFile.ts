import {
  ACCEPTED_IMAGE_TYPES,
  MAX_IMAGE_BYTES,
  MAX_IMAGE_MEGABYTES,
  type AcceptedImageType,
} from "./imageLimits";

export const ImageFileRefusal = {
  EMPTY: "empty",
  UNSUPPORTED_TYPE: "unsupported-type",
  TOO_LARGE: "too-large",
} as const;

export type ImageFileRefusal = (typeof ImageFileRefusal)[keyof typeof ImageFileRefusal];

export type ImageFileCheck =
  | { readonly ok: true }
  | { readonly ok: false; readonly reason: ImageFileRefusal; readonly message: string };

const REFUSAL_MESSAGE: Record<ImageFileRefusal, string> = {
  [ImageFileRefusal.EMPTY]: "This file is empty. Choose an image.",
  [ImageFileRefusal.UNSUPPORTED_TYPE]: "This file is not a JPEG, PNG, WebP or GIF image.",
  [ImageFileRefusal.TOO_LARGE]: `This image is larger than ${MAX_IMAGE_MEGABYTES} MB. Choose a smaller one.`,
};

function isAcceptedType(type: string): type is AcceptedImageType {
  return (ACCEPTED_IMAGE_TYPES as readonly string[]).includes(type);
}

function refuse(reason: ImageFileRefusal): ImageFileCheck {
  return { ok: false, reason, message: REFUSAL_MESSAGE[reason] };
}

/**
 * A convenience check made before a byte leaves the browser: it refuses what the picker's own
 * allowlist already excludes — an empty file, a type outside the allowlist, a size over the cap —
 * so the user hears it at once instead of after an upload. It never truncates, resizes or
 * re-encodes. The server stays the authority: `file.type` is only the browser's guess (it can be
 * empty or odd for a valid image), and the server decides from the bytes, so an image this
 * admits can still be refused.
 */
export function checkImageFile(file: Pick<File, "size" | "type">): ImageFileCheck {
  if (file.size === 0) return refuse(ImageFileRefusal.EMPTY);
  if (!isAcceptedType(file.type)) return refuse(ImageFileRefusal.UNSUPPORTED_TYPE);
  if (file.size > MAX_IMAGE_BYTES) return refuse(ImageFileRefusal.TOO_LARGE);
  return { ok: true };
}
