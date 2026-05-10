// Next.js convention file: rendered when a Server Component calls
// `forbidden()` from `next/navigation`. Maps to HTTP 403 (authenticated but
// lacking permission). Reuses the navigable `/unauthorized` route's UI so
// there is a single source of truth for the "Access denied" experience.
export { default } from "./(errors)/unauthorized/page";
