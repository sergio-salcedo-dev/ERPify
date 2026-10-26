import type { Metadata } from "next";
import { PlannedModulePage } from "@/app/backoffice/_components/PlannedModulePage";
import { PlannedModules } from "@/app/backoffice/_lib/plannedModules";

export const metadata: Metadata = {
  title: "Catalog",
};

export default function CatalogPage() {
  return <PlannedModulePage module={PlannedModules.CATALOG} />;
}
