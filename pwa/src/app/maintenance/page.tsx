import Link from "next/link";
import { Home, Wrench } from "lucide-react";
import { ErrorScreen } from "@/components/erpify";
import { buttonVariants } from "@/components/ui/button-variants";
import { cn } from "@/lib/utils";

export const metadata = {
  title: "Scheduled maintenance · Erpify",
  description: "Erpify is currently undergoing scheduled maintenance.",
};

const ACTION_BTN = "h-11 w-full px-6 text-base sm:w-auto sm:h-10 lg:h-11 lg:text-base";

export default function MaintenancePage() {
  return (
    <ErrorScreen
      testIdPrefix="maintenance"
      status="Error 503"
      title="Scheduled maintenance"
      description="Erpify is temporarily offline while we deploy improvements. We'll be back shortly — thank you for your patience."
      icon={Wrench}
      iconTone="warning"
      actions={
        <Link
          href="/"
          className={cn(buttonVariants({ size: "lg" }), ACTION_BTN, "maintenance__home-link")}
          data-icon="inline-start"
          title="Return to home"
          aria-label="Home"
          data-testid="maintenance__home-link"
        >
          <Home className="size-4" aria-hidden="true" />
          Return home
        </Link>
      }
    />
  );
}
