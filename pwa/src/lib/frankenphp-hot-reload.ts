/**
 * Build absolute Mercure URL for FrankenPHP file-watch hot reload in the browser.
 *
 * @see https://frankenphp.dev/docs/hot-reload/
 */
export function joinPublicOrigin(publicBase: string, subscribePath: string): string {
  const base = publicBase.replace(/\/$/, "");
  const path = subscribePath.startsWith("/") ? subscribePath : `/${subscribePath}`;
  return `${base}${path}`;
}

type HotReloadApiResponse = {
  enabled?: boolean;
  subscribePath?: string;
};

/**
 * Server-only: fetches subscribe path from Symfony when Next runs in development inside Docker.
 */
export async function fetchFrankenPhpHotReloadSubscribeUrl(): Promise<string | null> {
  if (process.env.NODE_ENV !== "development") {
    return null;
  }

  const internal = process.env.SYMFONY_INTERNAL_URL;
  const publicBase = process.env.NEXT_PUBLIC_SYMFONY_API_BASE_URL ?? "";
  if (!internal || !publicBase) {
    return null;
  }

  try {
    const res = await fetch(`${internal.replace(/\/$/, "")}/api/v1/dev/frankenphp-hot-reload`, {
      cache: "no-store",
    });
    if (!res.ok) {
      return null;
    }
    const data = (await res.json()) as HotReloadApiResponse;
    if (!data.enabled || !data.subscribePath) {
      return null;
    }
    return joinPublicOrigin(publicBase, data.subscribePath);
  } catch {
    return null;
  }
}
