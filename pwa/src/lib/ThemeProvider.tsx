"use client";

import { ThemeProvider as NextThemesProvider } from "next-themes";
import type { ComponentProps } from "react";

/**
 * Thin `"use client"` wrapper around `next-themes`' provider so the server
 * `app/layout.tsx` can mount theming without crossing the server/client
 * boundary itself. Pure framework glue — no domain identity — hence it lives
 * in `src/lib/`. Props pass straight through; the layout supplies the ERPify
 * defaults (`attribute="class"`, `Theme.SYSTEM`, `THEME_STORAGE_KEY`).
 */
export function ThemeProvider({ children, ...props }: ComponentProps<typeof NextThemesProvider>) {
  return <NextThemesProvider {...props}>{children}</NextThemesProvider>;
}
