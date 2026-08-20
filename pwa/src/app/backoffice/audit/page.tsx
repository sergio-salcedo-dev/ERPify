import type { Metadata } from "next";
import { Suspense } from "react";
import { AuditInvestigationScreen } from "./_components/AuditInvestigationScreen";
import { AuditTimelineSkeleton } from "@/context/backoffice/audit/infrastructure/ui/AuditTimelineSkeleton";

export const metadata: Metadata = {
  title: "Audit Logs",
};

/**
 * `/backoffice/audit` — the audit investigation admin UI over the 4.1 timeline read model. The screen
 * reads its whole state from URL params (`useSearchParams`), so it must sit under a Suspense boundary;
 * the fallback mirrors the timeline's loading skeleton to keep the first paint layout-stable.
 */
export default function AuditPage() {
  return (
    <Suspense fallback={<AuditTimelineSkeleton />}>
      <AuditInvestigationScreen />
    </Suspense>
  );
}
