import { notFound } from "next/navigation";
import { isDevToolsAvailable } from "@/context/shared/dev-tools/domain/isDevToolsAvailable";
import { ImageUploadHarness } from "./ImageUploadHarness";

export const metadata = {
  title: "Image Upload · Erpify (dev/test only)",
  description: "Harness for the shared image upload control. Hidden in production.",
};

/**
 * `/backoffice/dev-tools/image-upload` — drives `<ImageUploadField>` against a stub write URL so
 * set, replace, remove and every refusal can be exercised without a consumer screen. Hidden from
 * production through `isDevToolsAvailable()` (and the proxy short-circuit), like its siblings.
 */
export default function ImageUploadPage() {
  if (!isDevToolsAvailable()) {
    notFound();
  }
  return <ImageUploadHarness />;
}
