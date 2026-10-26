import type { Metadata } from "next";
import { PlannedModulePage } from "@/app/backoffice/_components/PlannedModulePage";
import { PlannedModules } from "@/app/backoffice/_lib/plannedModules";

export const metadata: Metadata = {
  title: "Purchase Orders",
};

export default function PurchaseOrdersPage() {
  return <PlannedModulePage module={PlannedModules.PURCHASE_ORDERS} />;
}
