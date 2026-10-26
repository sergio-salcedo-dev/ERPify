import type { Metadata } from "next";
import { PlannedModulePage } from "@/app/backoffice/_components/PlannedModulePage";
import { PlannedModules } from "@/app/backoffice/_lib/plannedModules";

export const metadata: Metadata = {
  title: "Brands & Manufacturers",
};

export default function CatalogBrandsPage() {
  return <PlannedModulePage module={PlannedModules.BRANDS} />;
}
