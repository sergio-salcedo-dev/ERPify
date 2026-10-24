import type { Metadata } from "next";
import { PlannedModulePage } from "@/app/backoffice/_components/PlannedModulePage";
import { PlannedModules } from "@/app/backoffice/_lib/plannedModules";

export const metadata: Metadata = {
  title: "Follow-ups",
};

export default function FollowUpsPage() {
  return <PlannedModulePage module={PlannedModules.FOLLOW_UPS} />;
}
