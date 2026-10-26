import type { Metadata } from "next";
import { PlannedModulePage } from "@/app/backoffice/_components/PlannedModulePage";
import { PlannedModules } from "@/app/backoffice/_lib/plannedModules";

export const metadata: Metadata = {
  title: "AI Reports",
};

export default function AiReportsPage() {
  return <PlannedModulePage module={PlannedModules.AI_REPORTS} />;
}
