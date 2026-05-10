// Next.js 15+ convention file: rendered when a Server Component calls
// `unauthorized()` from `next/navigation` (HTTP 401 — authentication
// required, despite the historical "Unauthorized" label). The actual UI
// lives in the error module's infrastructure/ui layer; this file is the
// thin Next binding. The same `<SignInRequiredScreen>` is reused by the
// navigable `/unauthenticated` route so the two surfaces stay visually
// identical.
export { SignInRequiredScreen as default } from "@/context/shared/error/infrastructure/ui";
