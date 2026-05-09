import Link from "next/link";
import { Home, LogIn } from "lucide-react";
import { ErrorScreen } from "@/components/erpify";
import { buttonVariants } from "@/components/ui/button-variants";
import { cn } from "@/lib/utils";

export const metadata = {
  title: "Sign in required · Erpify",
  description: "You need to sign in to access this resource.",
};

const ACTION_BTN = "h-11 w-full px-6 text-base sm:w-auto sm:h-10 lg:h-11 lg:text-base";

export default function UnauthenticatedPage() {
  return (
    <ErrorScreen
      testIdPrefix="unauthenticated"
      status="Error 401"
      title="Sign in required"
      description="Your session has expired or you are not signed in. Please sign in again to continue where you left off."
      icon={LogIn}
      iconTone="primary"
      actions={
        <>
          <Link
            href="/"
            className={cn(
              buttonVariants({ size: "lg" }),
              ACTION_BTN,
              "unauthenticated__signin-link",
            )}
            data-icon="inline-start"
            title="Sign in"
            aria-label="Sign in"
            data-testid="unauthenticated__signin-link"
          >
            <LogIn className="size-4" aria-hidden="true" />
            Sign in
          </Link>
          <Link
            href="/"
            className={cn(
              buttonVariants({ variant: "outline", size: "lg" }),
              ACTION_BTN,
              "unauthenticated__home-link",
            )}
            data-icon="inline-start"
            title="Return to home"
            aria-label="Home"
            data-testid="unauthenticated__home-link"
          >
            <Home className="size-4" aria-hidden="true" />
            Return home
          </Link>
        </>
      }
    />
  );
}
