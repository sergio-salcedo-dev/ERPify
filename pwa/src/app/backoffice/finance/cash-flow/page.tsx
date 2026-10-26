import type { Metadata } from "next";
import { PlannedModulePage } from "@/app/backoffice/_components/PlannedModulePage";
import { PlannedModules } from "@/app/backoffice/_lib/plannedModules";

export const metadata: Metadata = {
  title: "Cash Flow",
};

export default function FinanceCashFlowPage() {
  return <PlannedModulePage module={PlannedModules.CASH_FLOW} />;
}
