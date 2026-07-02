"use client";

import { ResourceViewToggle, type ResourceView } from "@/components/erpify";

export type BankAccountsView = ResourceView;

interface BankAccountsViewToggleProps {
  view: BankAccountsView;
  onViewChange: (next: BankAccountsView) => void;
}

export function BankAccountsViewToggle({
  view,
  onViewChange,
}: Readonly<BankAccountsViewToggleProps>) {
  return (
    <ResourceViewToggle
      view={view}
      onViewChange={onViewChange}
      ariaLabel="Bank accounts view"
      testIdPrefix="bank-accounts-list"
    />
  );
}
