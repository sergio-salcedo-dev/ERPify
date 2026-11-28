import { MAX_DOCUMENT_BYTES, MAX_DOCUMENT_MEBIBYTES } from "./documentLimits";

export const DocumentFileRefusal = {
  EMPTY: "empty",
  TOO_LARGE: "too-large",
} as const;

export type DocumentFileRefusal = (typeof DocumentFileRefusal)[keyof typeof DocumentFileRefusal];

export type DocumentFileCheck =
  | { readonly ok: true }
  | { readonly ok: false; readonly reason: DocumentFileRefusal; readonly message: string };

const REFUSAL_MESSAGE: Record<DocumentFileRefusal, string> = {
  [DocumentFileRefusal.EMPTY]: "This file is empty. Choose the document to keep.",
  [DocumentFileRefusal.TOO_LARGE]: `This file is larger than ${MAX_DOCUMENT_MEBIBYTES} MB. Choose a smaller one.`,
};

/**
 * A check made before anything is declared: an empty file and one over the cap are refused at once,
 * so nothing is reserved on the server for an upload certain to fail. Nothing is truncated, and no
 * format is judged — an evidence original is kept byte for byte whatever it is.
 */
export function checkDocumentFile(file: Pick<File, "size">): DocumentFileCheck {
  if (file.size === 0) {
    return { ok: false, reason: DocumentFileRefusal.EMPTY, message: REFUSAL_MESSAGE.empty };
  }
  if (file.size > MAX_DOCUMENT_BYTES) {
    return {
      ok: false,
      reason: DocumentFileRefusal.TOO_LARGE,
      message: REFUSAL_MESSAGE["too-large"],
    };
  }
  return { ok: true };
}
