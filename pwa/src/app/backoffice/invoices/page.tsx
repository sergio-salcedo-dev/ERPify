import type { Metadata } from "next";
import { PlannedModulePage } from "@/app/backoffice/_components/PlannedModulePage";
import { PlannedModules } from "@/app/backoffice/_lib/plannedModules";

export const metadata: Metadata = {
  title: "Invoicing",
};

export default function InvoicesPage() {
  return <PlannedModulePage module={PlannedModules.INVOICES} />;
}
