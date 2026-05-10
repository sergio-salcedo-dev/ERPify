import { notFound } from "next/navigation";
import { isDevToolsAvailable } from "@/context/shared/dev-tools/domain/isDevToolsAvailable";
import { DevToolsMenu } from "@/context/shared/dev-tools/infrastructure/ui";

export const metadata = {
  title: "Dev Tools · Erpify (dev/test only)",
  description: "Internal hub for QA and engineering. Hidden in production.",
};

/**
 * `/dev-tools` — public route bound to the dev-tools module's
 * `<DevToolsMenu>`. The page calls `notFound()` in production so the URL
 * is unreachable for real users, mirroring the same gating model used by
 * `/dev-throw` and `/dev-error-gallery`.
 */
export default function DevToolsPage() {
  if (!isDevToolsAvailable()) {
    notFound();
  }
  return <DevToolsMenu />;
}
