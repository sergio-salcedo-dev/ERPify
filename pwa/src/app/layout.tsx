import "reflect-metadata";
import type { Metadata } from "next";
import "./globals.css";
import { Geist, Geist_Mono } from "next/font/google";
import { fetchFrankenPhpHotReloadSubscribeUrl } from "@/lib/frankenphp-hot-reload";
import { cn } from "@/lib/utils";
import { SonnerToaster } from "@/context/shared/infrastructure/Notification/Toast/SonnerToaster";
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
    <html lang="en" className={cn("font-sans", geistSans.variable, geistMono.variable)}>
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
        {children}
        <SonnerToaster />
      </body>
    </html>
  );
}
