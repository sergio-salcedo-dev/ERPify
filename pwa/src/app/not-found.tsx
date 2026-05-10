import { FileQuestion } from "lucide-react";
import { ErrorActions, ErrorScreen } from "@/context/shared/error/infrastructure/ui";
import { IconTone } from "@/context/shared/error/domain/IconTone";

export default function NotFound() {
  return (
    <ErrorScreen
      testIdPrefix="not-found"
      status="Error 404"
      title="Page not found"
      description="The page you're looking for doesn't exist or has been moved. Check the URL or return to a known location."
      icon={FileQuestion}
      iconTone={IconTone.PRIMARY}
      actions={<ErrorActions />}
    />
  );
}
