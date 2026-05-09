import Link from "next/link";
import { FileQuestion, Home } from "lucide-react";
import { ErrorScreen } from "@/components/erpify";
import { buttonVariants } from "@/components/ui/button-variants";
import { cn } from "@/lib/utils";

const ACTION_BTN = "h-11 w-full px-6 text-base sm:w-auto sm:h-10 lg:h-11 lg:text-base";

export default function NotFound() {
  return (
    <ErrorScreen
      testIdPrefix="not-found"
      status="Error 404"
      title="Page not found"
      description="The page you're looking for doesn't exist or has been moved. Check the URL or return to a known location."
      icon={FileQuestion}
      iconTone="primary"
      actions={
        <>
          <Link
            href="/"
            className={cn(buttonVariants({ size: "lg" }), ACTION_BTN, "not-found__home-link")}
            data-icon="inline-start"
            title="Return to home"
            aria-label="Home"
            data-testid="not-found__home-link"
          >
            <Home className="size-4" aria-hidden="true" />
            Return home
          </Link>
          <Link
            href="/backoffice"
            className={cn(
              buttonVariants({ variant: "outline", size: "lg" }),
              ACTION_BTN,
              "not-found__backoffice-link",
            )}
            title="Go to BackOffice"
            aria-label="BackOffice"
            data-testid="not-found__backoffice-link"
          >
            Go to BackOffice
          </Link>
        </>
      }
    />
  );
}
