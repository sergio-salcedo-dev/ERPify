import { SignInRequiredScreen } from "@/context/shared/error/infrastructure/ui";

export const metadata = {
  title: "Sign in required · Erpify",
  description: "You need to sign in to access this resource.",
};

// Reuses the same `<SignInRequiredScreen>` rendered by the Next 15+
// `app/unauthorized.tsx` convention file, so the navigable URL and the
// `unauthorized()`-driven boundary stay visually identical.
export default SignInRequiredScreen;
