// Next.js convention: last-resort root boundary that fires when RootLayout
// itself crashes (and therefore must own its own <html>/<body>). The actual
// UI lives in the error module's infrastructure/ui layer; this file is the
// thin Next binding.
"use client";

export { RootErrorBoundary as default } from "@/context/shared/error/infrastructure/ui";
