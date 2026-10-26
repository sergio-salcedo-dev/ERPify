import type { Metadata } from "next";
import { PlannedModulePage } from "@/app/backoffice/_components/PlannedModulePage";
import { PlannedModules } from "@/app/backoffice/_lib/plannedModules";

export const metadata: Metadata = {
  title: "Stock Control",
};

export default function CatalogStockPage() {
  return <PlannedModulePage module={PlannedModules.STOCK} />;
}
