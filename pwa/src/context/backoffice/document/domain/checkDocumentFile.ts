/**
 * The largest original the Documents upload accepts, in bytes. Mirrors the API's
 * `UploadLimits::MAX_ORIGINAL_BYTES` and the Caddy body bound of the content route (100 MiB).
 */
export const MAX_DOCUMENT_BYTES = 104_857_600;

/** The same bound in the unit the copy speaks. */
export const MAX_DOCUMENT_MEGABYTES = 100;

/** The API's bound on an original filename, in UTF-8 bytes after NFC normalisation. */
export const MAX_FILENAME_BYTES = 255;

/**
 * The characters the API refuses in an original filename (`OriginalFilename`): controls, lone
 * surrogates, private-use and unassigned code points, line and paragraph separators, and every
 * format character except the zero-width joiner and non-joiner, which some scripts need.
 */
const REFUSED_CHARACTERS = /[\p{Cc}\p{Cs}\p{Co}\p{Cn}\p{Zl}\p{Zp}]|(?![\u200C\u200D])\p{Cf}/u;

export type DocumentFileCheck =
  { readonly ok: true } | { readonly ok: false; readonly message: string };

/**
 * The original filename as the API validates it: NFC-normalised. A name picked on macOS arrives in
 * NFD, which the API refuses as a whole; normalising it here changes no visible character.
 */
export function normalisedFilename(file: Pick<File, "name">): string {
  return file.name.normalize("NFC");
}

/**
 * A convenience check made before a byte leaves the browser: an empty file, one over the size cap,
 * or a name the API would refuse — not well-formed Unicode, a refused character, blank, or too
 * long. Any type is accepted — an evidence-class original is kept as it is, whatever it is — and
 * the server stays the authority on every rule, the finer filename rules included.
 */
export function checkDocumentFile(file: Pick<File, "name" | "size">): DocumentFileCheck {
  if (file.size === 0) return { ok: false, message: "This file is empty. Choose another file." };
  if (file.size > MAX_DOCUMENT_BYTES) {
    return {
      ok: false,
      message: `This file is larger than ${MAX_DOCUMENT_MEGABYTES} MB. Choose a smaller one.`,
    };
  }
  if (!file.name.isWellFormed() || REFUSED_CHARACTERS.test(file.name)) {
    return {
      ok: false,
      message: "This file name contains characters that cannot be kept. Rename it and try again.",
    };
  }
  const name = normalisedFilename(file);
  if (name.trim() === "") {
    return { ok: false, message: "This file has no name. Rename it and try again." };
  }
  if (new TextEncoder().encode(name).length > MAX_FILENAME_BYTES) {
    return { ok: false, message: "This file name is too long. Rename it and try again." };
  }
  return { ok: true };
}
