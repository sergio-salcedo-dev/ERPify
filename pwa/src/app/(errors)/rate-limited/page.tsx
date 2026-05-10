import { Hourglass } from "lucide-react";
import { ErrorActions, ErrorScreen } from "@/context/shared/error/infrastructure/ui";
import { IconTone } from "@/context/shared/error/domain/IconTone";

export const metadata = {
  title: "Too many requests · Erpify",
  description: "You have reached the request limit. Please wait and try again.",
};

export default function RateLimitedPage() {
  return (
    <ErrorScreen
      testIdPrefix="rate-limited"
      status="Error 429"
      title="Too many requests"
      description="You've hit our request limit. Please wait a few moments before trying again or contact support to request higher quotas."
      icon={Hourglass}
      iconTone={IconTone.WARNING}
      actions={<ErrorActions />}
    />
  );
}
