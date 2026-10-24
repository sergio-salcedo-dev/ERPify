import type { Metadata } from "next";
import { PlannedModulePage } from "@/app/backoffice/_components/PlannedModulePage";
import { PlannedModules } from "@/app/backoffice/_lib/plannedModules";

export const metadata: Metadata = {
  title: "Delivery Notes",
};

export default function DeliveryNotesPage() {
  return <PlannedModulePage module={PlannedModules.DELIVERY_NOTES} />;
}
