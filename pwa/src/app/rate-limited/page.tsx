import Link from "next/link";
import { Home, Hourglass } from "lucide-react";
import { ErrorScreen } from "@/components/erpify";
import { buttonVariants } from "@/components/ui/button-variants";
import { cn } from "@/lib/utils";

export const metadata = {
  title: "Too many requests · Erpify",
  description: "You have reached the request limit. Please wait and try again.",
};

const ACTION_BTN = "h-11 w-full px-6 text-base sm:w-auto sm:h-10 lg:h-11 lg:text-base";

export default function RateLimitedPage() {
  return (
    <ErrorScreen
      testIdPrefix="rate-limited"
      status="Error 429"
      title="Too many requests"
      description="You've hit our request limit. Please wait a few moments before trying again. If you're running automation, slow down or contact support to request higher quotas."
      icon={Hourglass}
      iconTone="warning"
      actions={
        <Link
          href="/"
          className={cn(buttonVariants({ size: "lg" }), ACTION_BTN, "rate-limited__home-link")}
          data-icon="inline-start"
          title="Return to home"
          aria-label="Home"
          data-testid="rate-limited__home-link"
        >
          <Home className="size-4" aria-hidden="true" />
          Return home
        </Link>
      }
    />
  );
}
