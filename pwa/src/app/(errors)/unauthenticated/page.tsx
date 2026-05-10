import { LogIn } from "lucide-react";
import { ErrorActions, ErrorScreen } from "@/context/shared/error/infrastructure/ui";
import { IconTone } from "@/context/shared/error/domain/IconTone";

export const metadata = {
  title: "Sign in required · Erpify",
  description: "You need to sign in to access this resource.",
};

export default function UnauthenticatedPage() {
  return (
    <ErrorScreen
      testIdPrefix="unauthenticated"
      status="Error 401"
      title="Sign in required"
      description="Your session has expired or you are not signed in. Please sign in again to continue where you left off."
      icon={LogIn}
      iconTone={IconTone.PRIMARY}
      actions={<ErrorActions />}
    />
  );
}
