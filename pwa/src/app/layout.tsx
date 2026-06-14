import "reflect-metadata";
import type { Metadata } from "next";
import "./globals.css";
import { Geist, Geist_Mono } from "next/font/google";
import { fetchFrankenPhpHotReloadSubscribeUrl } from "@/lib/frankenphp-hot-reload";
import { cn } from "@/lib/utils";
import { ThemeProvider } from "@/lib/ThemeProvider";
import { Theme, THEME_STORAGE_KEY } from "@/context/shared/domain/types/theme";
import { SonnerToaster } from "@/context/shared/infrastructure/Notification/Toast/SonnerToaster";
import { AuthProvider } from "@/context/shared/access/infrastructure/ui";
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
  const frankenHotReloadUrl = await fetchFrankenPhpHotReloadSubscribeUrl();

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
          storageKey={THEME_STORAGE_KEY}
          disableTransitionOnChange
        >
          <AuthProvider>{children}</AuthProvider>
          <SonnerToaster />
        </ThemeProvider>
      </body>
    </html>
  );
}
