/**
 * The cheap half of the API's `OriginalFilename` rules, checked before anything is declared so a name
 * certain to be refused (`invalid-filename`) costs no upload. Mirrored: well-formed UTF-16 (a lone
 * surrogate cannot be percent-encoded at all), already in NFC, 1 to 255 bytes of UTF-8, not blank
 * once whitespace is trimmed, and no control (Cc), surrogate (Cs), private-use (Co), line or
 * paragraph separator (Zl, Zp) or format character (Cf) other than the zero-width joiner and
 * non-joiner. Not mirrored: unassigned code points (Cn) — which ones are unassigned depends on the
 * Unicode version of the engine, and a browser ahead of or behind the server's ICU would disagree.
 * The server stays the authority.
 */
const MAX_FILENAME_BYTES = 255;

const REFUSED_CODE_POINT = /[\p{Cc}\p{Co}\p{Zl}\p{Zp}]|(?![‌‍])\p{Cf}/u;

export type FilenameCheck =
  { readonly ok: true } | { readonly ok: false; readonly message: string };

const REFUSED: FilenameCheck = {
  ok: false,
  message:
    "This file name cannot be kept: it is empty, longer than 255 bytes, or contains invisible or control characters. Rename the file and choose it again.",
};

function isWellFormed(value: string): boolean {
  try {
    encodeURIComponent(value);
    return true;
  } catch {
    return false;
  }
}

export function checkOriginalFilename(name: string): FilenameCheck {
  if (name === "" || !isWellFormed(name)) return REFUSED;
  if (name.normalize("NFC") !== name) return REFUSED;
  const bytes = new TextEncoder().encode(name).length;
  if (bytes > MAX_FILENAME_BYTES) return REFUSED;
  if (name.trim() === "") return REFUSED;
  if (REFUSED_CODE_POINT.test(name)) return REFUSED;
  return { ok: true };
}
