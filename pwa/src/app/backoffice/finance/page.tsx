import type { Metadata } from "next";
import { PlannedModulePage } from "@/app/backoffice/_components/PlannedModulePage";
import { PlannedModules } from "@/app/backoffice/_lib/plannedModules";

export const metadata: Metadata = {
  title: "Finance",
};

export default function FinancePage() {
  return <PlannedModulePage module={PlannedModules.FINANCE} />;
}
