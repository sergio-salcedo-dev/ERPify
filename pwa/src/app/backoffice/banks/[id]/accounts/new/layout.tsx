import type { Metadata } from "next";

// Next's route announcer reads `document.title` and speaks only when it changes, so a route
// inheriting the root title arrives unnamed for assistive technology. Its sibling create routes
// declare this on the page itself; this segment cannot, because its page is a Client Component
// and `metadata` is server-only. A layout is the seam that stays declarative — the alternative,
// taking `params` as a prop so the page can be a Server Component, is the right cleanup but has
// no precedent in this tree and would change a route's render model for a title.
export const metadata: Metadata = {
  title: "New account",
};

export default function NewBankAccountLayout({
  children,
}: Readonly<{ children: React.ReactNode }>) {
  return children;
}
