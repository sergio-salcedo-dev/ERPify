import type { Metadata } from "next";
import { PlannedModulePage } from "@/app/backoffice/_components/PlannedModulePage";
import { PlannedModules } from "@/app/backoffice/_lib/plannedModules";

export const metadata: Metadata = {
  title: "Technical Explorer",
};

export default function DocsPage() {
  return <PlannedModulePage module={PlannedModules.TECHNICAL_EXPLORER} />;
}
