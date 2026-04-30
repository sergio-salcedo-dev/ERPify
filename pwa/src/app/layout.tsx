import "reflect-metadata";
import type { Metadata } from "next";
import "./globals.css";
import { Geist, Geist_Mono } from "next/font/google";
import { fetchFrankenPhpHotReloadSubscribeUrl } from "@/lib/frankenphp-hot-reload";
import { cn } from "@/lib/utils";

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

export default async function RootLayout({ children }: { children: React.ReactNode }) {
  const frankenHotReloadUrl = await fetchFrankenPhpHotReloadSubscribeUrl();

  return (
    <html lang="en" className={cn("font-sans", geistSans.variable, geistMono.variable)}>
      <head>
        {frankenHotReloadUrl ? (
          <>
            <meta name="frankenphp-hot-reload:url" content={frankenHotReloadUrl} />
            <script src="https://cdn.jsdelivr.net/npm/idiomorph" />
            <script type="module" src="https://cdn.jsdelivr.net/npm/frankenphp-hot-reload/+esm" />
          </>
        ) : null}
      </head>
      <body suppressHydrationWarning>{children}</body>
    </html>
  );
}
