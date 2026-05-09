import Link from "next/link";
import { Home, Lock } from "lucide-react";
import { ErrorScreen } from "@/components/erpify";
import { buttonVariants } from "@/components/ui/button-variants";
import { cn } from "@/lib/utils";

export const metadata = {
  title: "Unauthorized · Erpify",
  description: "You do not have permission to access this resource.",
};

const ACTION_BTN = "h-11 w-full px-6 text-base sm:w-auto sm:h-10 lg:h-11 lg:text-base";

export default function UnauthorizedPage() {
  return (
    <ErrorScreen
      testIdPrefix="unauthorized"
      status="Error 403"
      title="Access denied"
      description="You do not have permission to view this resource. If you believe this is a mistake, contact your administrator to request the appropriate access."
      icon={Lock}
      iconTone="warning"
      actions={
        <>
          <Link
            href="/"
            className={cn(buttonVariants({ size: "lg" }), ACTION_BTN, "unauthorized__home-link")}
            data-icon="inline-start"
            title="Return to home"
            aria-label="Home"
            data-testid="unauthorized__home-link"
          >
            <Home className="size-4" aria-hidden="true" />
            Return home
          </Link>
          <Link
            href="/backoffice"
            className={cn(
              buttonVariants({ variant: "outline", size: "lg" }),
              ACTION_BTN,
              "unauthorized__backoffice-link",
            )}
            title="Go to BackOffice"
            aria-label="BackOffice"
            data-testid="unauthorized__backoffice-link"
          >
            Go to BackOffice
          </Link>
        </>
      }
    />
  );
}
