import "reflect-metadata";
import type { Metadata } from "next";
import "./globals.css";
import { Geist, Geist_Mono } from "next/font/google";
import { fetchFrankenPhpHotReloadSubscribeUrl } from "@/context/shared/dev-tools/infrastructure/frankenphpHotReload";
import { cn } from "@/components/cn";
import { ThemeProvider } from "@/context/shared/theme/infrastructure/ThemeProvider";
import { Theme, THEME_STORAGE_KEY } from "@/context/shared/theme/domain/Theme";
import { SonnerToaster } from "@/context/shared/notification/infrastructure/Toast/SonnerToaster";
import { AuthProvider } from "@/context/shared/access/infrastructure/ui";
import { isDevToolsAvailable } from "@/context/shared/dev-tools/domain/isDevToolsAvailable";
import { isAutomatedTestRequest } from "@/context/shared/dev-tools/infrastructure/isAutomatedTestRequest";
import { SymfonyDebugToolbar } from "@/context/shared/dev-tools/infrastructure/ui/SymfonyDebugToolbar";
import { headers } from "next/headers";
import Script from "next/script";

const geistSans = Geist({
  subsets: ["latin"],
  variable: "--font-sans",
  preload: false,
});

const geistMono = Geist_Mono({
  subsets: ["latin"],
  variable: "--font-mono",
  preload: false,
});

export const metadata: Metadata = {
  title: "Erpify - Construction ERP/CRM",
  description: "Modern ERP and CRM solution specialized for the construction industry.",
  icons: {
    icon: "/favicon.ico",
  },
};

export default async function RootLayout({ children }: Readonly<{ children: React.ReactNode }>) {
  // Reading the request headers opts every page into per-request rendering, which the
  // CSP nonce requires: `proxy.ts` mints it per request and Next stamps it onto its own
  // scripts only while rendering one. Static prerendering has no request to read it from.
  const nonce = (await headers()).get("x-nonce") ?? undefined;
  const frankenHotReloadUrl = await fetchFrankenPhpHotReloadSubscribeUrl();
  // Skip the dev toolbar under Playwright: its injected profiler DOM (the AJAX
  // panel grows a row per /api call) otherwise pollutes document-wide e2e
  // locators. `&&` short-circuits in prod so `headers()` is never read there.
  const showDebugToolbar = isDevToolsAvailable() && !(await isAutomatedTestRequest());

  return (
    <html
      lang="en"
      className={cn("font-sans", geistSans.variable, geistMono.variable)}
      suppressHydrationWarning
    >
      <head>
        {frankenHotReloadUrl ? (
          <>
            <meta name="frankenphp-hot-reload:url" content={frankenHotReloadUrl} />
            <Script src="https://cdn.jsdelivr.net/npm/idiomorph" strategy="afterInteractive" />
            <Script
              type="module"
              src="https://cdn.jsdelivr.net/npm/frankenphp-hot-reload/+esm"
              strategy="afterInteractive"
            />
          </>
        ) : null}
      </head>
      <body suppressHydrationWarning>
        <ThemeProvider
          attribute="class"
          defaultTheme={Theme.SYSTEM}
          enableSystem
          nonce={nonce}
          storageKey={THEME_STORAGE_KEY}
          disableTransitionOnChange
        >
          {/* The toast viewport is global infrastructure, a sibling of AuthProvider so it
              never unmounts with the guarded subtree — including under <SessionExpiryCurtain>,
              which clears any stale toast itself (`dismissAll()`) the instant it engages,
              rather than relying on an incidental unmount to hide one. */}
          <AuthProvider>{children}</AuthProvider>
          <SonnerToaster />
          {showDebugToolbar ? <SymfonyDebugToolbar /> : null}
        </ThemeProvider>
      </body>
    </html>
  );
}
