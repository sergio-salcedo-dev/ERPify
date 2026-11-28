const MAX_DOWNLOAD_NAME_LENGTH = 255;

/** Letters and digits of any script, space, dot, underscore, parentheses and hyphen — nothing else. */
const ALLOWED_DOWNLOAD_NAME = /^[\p{L}\p{N} ._()-]+$/u;

/**
 * The name a download link may suggest for a filed original, or `null` when the person-supplied
 * name is not one to hand to the `download` attribute as it is. An explicit allowlist rather than
 * a list of dangerous characters: after NFC it must be at most 255 characters, made only of
 * {@link ALLOWED_DOWNLOAD_NAME}, and not start with a dot (a hidden file on most systems). A name
 * that fails is not repaired — the caller downloads unnamed and the read route names the file.
 */
export function safeDownloadName(name: string | null | undefined): string | null {
  if (typeof name !== "string") return null;
  const normalised = name.normalize("NFC");
  if (normalised.length === 0 || normalised.length > MAX_DOWNLOAD_NAME_LENGTH) return null;
  if (normalised.startsWith(".")) return null;
  return ALLOWED_DOWNLOAD_NAME.test(normalised) ? normalised : null;
}
