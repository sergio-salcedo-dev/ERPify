import type { Metadata } from "next";
import { PlannedModulePage } from "@/app/backoffice/_components/PlannedModulePage";
import { PlannedModules } from "@/app/backoffice/_lib/plannedModules";

export const metadata: Metadata = {
  title: "Data Dictionary",
};

export default function DocsDictionaryPage() {
  return <PlannedModulePage module={PlannedModules.DATA_DICTIONARY} />;
}
