import type { Metadata } from "next";
import { RecoveryRedeemForm } from "../_components/RecoveryRedeemForm";

export const metadata: Metadata = {
  title: "Use your recovery secret",
};

export default function RecoveryPage() {
  return <RecoveryRedeemForm />;
}
