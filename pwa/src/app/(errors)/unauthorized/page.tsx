export const metadata = {
  title: "Unauthorized · Erpify",
  description: "You do not have permission to access this resource.",
};

// Reuses the same `<AccessDeniedScreen>` rendered by the Next 15+
// `app/forbidden.tsx` convention file, so the navigable URL and the
// `forbidden()`-driven boundary stay visually identical.
export { AccessDeniedScreen as default } from "@/context/shared/error/infrastructure/ui";
