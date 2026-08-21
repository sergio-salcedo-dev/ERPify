import type { Metadata } from "next";
import { Suspense } from "react";
import { Spinner } from "@/components/erpify";
import { ResetPasswordForm } from "../_components/ResetPasswordForm";

export const metadata: Metadata = {
  title: "Choose a new password",
};

export default function ResetPasswordPage() {
  // ResetPasswordForm reads the reset token via useSearchParams, which Next 16
  // requires to sit under a Suspense boundary.
  return (
    <Suspense
      fallback={
        <div className="flex justify-center py-4" data-testid="reset-password-page__loading">
          <Spinner className="size-6" />
        </div>
      }
    >
      <ResetPasswordForm />
    </Suspense>
  );
}
