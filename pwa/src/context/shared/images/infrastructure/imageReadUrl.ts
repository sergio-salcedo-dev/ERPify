import { safeHref } from "@/context/shared/navigation/domain/safeHref";
import { API_ENDPOINTS } from "@/context/shared/http-client/infrastructure/ApiEndpoints";

function apiBase(): string {
  return (process.env.NEXT_PUBLIC_API_BASE_URL?.trim() ?? "").replace(/\/$/, "");
}

/**
 * The URL an `<img>` loads an image from: the shared read route plus the id, which is a cache key
 * and a presence flag, never a URL the API hands over. `null` when no id is held or the composed
 * value fails {@link safeHref}, so a caller renders its placeholder rather than an empty `src`.
 */
export function imageReadUrl(imageId: string | null | undefined): string | null {
  if (typeof imageId !== "string" || imageId.trim() === "") return null;
  const url = safeHref(`${apiBase()}${API_ENDPOINTS.IMAGES.GET(imageId)}`, "");
  return url === "" ? null : url;
}
