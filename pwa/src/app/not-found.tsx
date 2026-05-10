// Next.js convention: global 404 fallback. The actual UI lives in the
// error module's infrastructure/ui layer; this file is the thin Next
// binding.
export { NotFoundScreen as default } from "@/context/shared/error/infrastructure/ui";
