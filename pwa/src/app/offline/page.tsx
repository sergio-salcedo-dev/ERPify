import Link from "next/link";
import { Home, WifiOff } from "lucide-react";
import { ErrorScreen } from "@/components/erpify";
import { buttonVariants } from "@/components/ui/button-variants";
import { cn } from "@/lib/utils";

export const metadata = {
  title: "Offline · Erpify",
  description: "You are currently offline. Reconnect to continue using Erpify.",
};

const ACTION_BTN = "h-11 w-full px-6 text-base sm:w-auto sm:h-10 lg:h-11 lg:text-base";

export default function OfflinePage() {
  return (
    <ErrorScreen
      testIdPrefix="offline"
      status="No connection"
      title="You're offline"
      description="Erpify could not reach the network. Check your connection and try again — any work in progress was saved locally and will sync when you're back online."
      icon={WifiOff}
      iconTone="muted"
      actions={
        <Link
          href="/"
          className={cn(buttonVariants({ size: "lg" }), ACTION_BTN, "offline__home-link")}
          data-icon="inline-start"
          title="Return to home"
          aria-label="Home"
          data-testid="offline__home-link"
        >
          <Home className="size-4" aria-hidden="true" />
          Return home
        </Link>
      }
    />
  );
}
