import { safeHref } from "@/context/shared/navigation/domain/safeHref";
import { API_ENDPOINTS } from "@/context/shared/http-client/infrastructure/ApiEndpoints";

function apiBase(): string {
  return (process.env.NEXT_PUBLIC_API_BASE_URL?.trim() ?? "").replace(/\/$/, "");
}

/**
 * The URL a download link reads a filed original from: the audited Documents read route plus the
 * id (encoded by the registry). The browser sends the session cookie; only a `ready` document is
 * readable, so callers render the link for that state alone. `null` when no id is held or the
 * composed value fails {@link safeHref}.
 */
export function documentReadUrl(documentId: string | null | undefined): string | null {
  if (typeof documentId !== "string" || documentId.trim() === "") return null;
  const url = safeHref(`${apiBase()}${API_ENDPOINTS.DOCUMENTS.CONTENT(documentId)}`, "");
  return url === "" ? null : url;
}
