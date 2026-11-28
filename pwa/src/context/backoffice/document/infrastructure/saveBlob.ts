/**
 * Hands the browser a blob to save under `filename`: a detached anchor carrying a same-document
 * `blob:` URL and the `download` attribute, clicked once and revoked on the next task. The URL is
 * minted here from bytes already read, never taken from a payload, so no scheme check applies; the
 * name is the caller's sanitised one, and the browser sanitises a `download` name again.
 */
export function saveBlob(blob: Blob, filename: string): void {
  const url = URL.createObjectURL(blob);
  const anchor = document.createElement("a");
  anchor.href = url;
  anchor.download = filename;
  anchor.rel = "noopener";
  anchor.click();
  setTimeout(() => URL.revokeObjectURL(url), 0);
}
