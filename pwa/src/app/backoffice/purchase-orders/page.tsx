import type { Metadata } from "next";

export const metadata: Metadata = {
  title: "Purchase Orders",
};

export default function PurchaseOrdersPage() {
  return <h1 className="text-foreground text-2xl font-semibold tracking-tight">Purchase Orders</h1>;
}
