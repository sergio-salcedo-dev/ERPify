// Next.js 15+ convention file: rendered when a Server Component calls
// `forbidden()` from `next/navigation` (HTTP 403 — authenticated but
// lacks permission). The actual UI lives in the error module's
// infrastructure/ui layer; this file is the thin Next binding. The same
// `<AccessDeniedScreen>` is reused by the navigable
// `/unauthorized` route so the two surfaces stay visually identical.
export { AccessDeniedScreen as default } from "@/context/shared/error/infrastructure/ui";
