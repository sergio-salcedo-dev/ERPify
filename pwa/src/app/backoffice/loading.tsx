/**
 * Instant fallback while a back-office route segment renders on the server. Every
 * back-office page is rendered per request (the CSP nonce), so without a boundary
 * a navigation shows the previous screen frozen until the server answers. Decorative
 * and `aria-hidden`: the destination page announces its own state, and a title-less
 * live region here would speak on every transition.
 */
export default function BackOfficeLoading() {
  return (
    <div aria-hidden="true" aria-busy="true" className="animate-pulse space-y-4 p-4">
      <div className="bg-muted h-8 w-1/3 rounded" />
      <div className="bg-muted h-4 w-2/3 rounded" />
      <div className="space-y-3 pt-4">
        <div className="bg-muted h-12 w-full rounded" />
        <div className="bg-muted h-12 w-full rounded" />
        <div className="bg-muted h-12 w-full rounded" />
      </div>
    </div>
  );
}
