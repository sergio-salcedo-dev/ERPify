// Next.js convention file: rendered when a Server Component calls
// `unauthorized()` from `next/navigation`. Maps to HTTP 401 (authentication
// required). Reuses the navigable `/unauthenticated` route's UI so there is a
// single source of truth for the "Sign in required" experience.
//
// Note on naming: HTTP 401 is historically labelled "Unauthorized" but
// semantically means "Unauthenticated" — that's why the navigable route lives
// at `/unauthenticated/page.tsx` while this Next-convention file mirrors the
// HTTP wording.
export { default } from "./unauthenticated/page";
