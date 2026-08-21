import type { Metadata } from "next";
import { Suspense } from "react";
import { Spinner } from "@/components/erpify";
import { TokenActionScreen } from "../_components/TokenActionScreen";

export const metadata: Metadata = {
  title: "Create your password",
};

export default function AcceptInvitationPage() {
  // TokenActionScreen reads the invitation token via useSearchParams, which
  // Next 16 requires to sit under a Suspense boundary. AuthLayout (the (auth)
  // segment layout) supplies the centered card and the noindex robots tag.
  return (
    <Suspense
      fallback={
        <div className="flex justify-center py-4" data-testid="accept-invitation-page__loading">
          <Spinner className="size-6" />
        </div>
      }
    >
      <TokenActionScreen />
    </Suspense>
  );
}
