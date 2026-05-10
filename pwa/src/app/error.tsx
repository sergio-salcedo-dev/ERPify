// Next.js convention: segment-level error boundary. The actual UI lives in
// the error module's infrastructure/ui layer alongside the rest of the
// error surfaces; this file is the thin Next binding.
"use client";

export { SegmentErrorBoundary as default } from "@/context/shared/error/infrastructure/ui";
