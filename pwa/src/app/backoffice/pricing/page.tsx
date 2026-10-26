import type { Metadata } from "next";
import { PlannedModulePage } from "@/app/backoffice/_components/PlannedModulePage";
import { PlannedModules } from "@/app/backoffice/_lib/plannedModules";

export const metadata: Metadata = {
  title: "Price Lists",
};

export default function PricingPage() {
  return <PlannedModulePage module={PlannedModules.PRICING} />;
}
