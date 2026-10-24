import type { LucideIcon } from "lucide-react";
import type { NavAction } from "@/app/backoffice/_lib/backofficeMenu";

interface MobileNavLeaf {
  name: string;
  path: string;
  icon?: LucideIcon;
  testId?: string;
  action?: NavAction;
  isBusy?: boolean;
  isActive?: boolean;
  isPlanned?: boolean;
}

interface MobileNavEntryProps {
  name: string;
  path: string;
  icon: LucideIcon;
  testId?: string;
  isActive: boolean;
  isPlanned?: boolean;
  subItems?: readonly MobileNavLeaf[];
  onNavigate: (path: string, action?: NavAction) => void;
}

function SoonMarker() {
  return (
    <>
      <span
        className="bo-layout__mobile-soon border-border text-muted-foreground ml-auto shrink-0 rounded border px-1 text-2xs leading-4 font-medium"
        aria-hidden="true"
      >
        Soon
      </span>
      <span className="sr-only">, coming soon</span>
    </>
  );
}

/**
 * One entry of the mobile drawer: the parent navigates and its leaves are listed under it, so there
 * is nothing to toggle. Active and planned states are decided by the layout, the same way the
 * desktop sidebar receives them, so the two surfaces light the same entry for the same route.
 *
 * A parent whose every leaf is planned lists none of them: on a phone the drawer is one long
 * column, and listing two dozen "Soon" leaves pushed the working modules below several screens of
 * them. The parent still opens its planned page, and "Go to" still finds each leaf by name.
 */
export function MobileNavEntry({
  name,
  path,
  icon: Icon,
  testId,
  isActive,
  isPlanned,
  subItems,
  onNavigate,
}: Readonly<MobileNavEntryProps>) {
  const hasActiveLeaf = subItems?.some((entry) => entry.isActive) ?? false;
  return (
    <div className="bo-layout__sidebar-mobile-item-wrapper">
      <button
        type="button"
        onClick={() => onNavigate(path)}
        title={isPlanned ? `${name} (coming soon)` : name}
        aria-current={isActive && !hasActiveLeaf ? "page" : undefined}
        data-testid={testId ? `${testId}--mobile` : undefined}
        className={`bo-layout__sidebar-mobile-link w-full flex items-center gap-3 p-3 rounded-md font-semibold transition-all ${
          isActive ? "bg-primary/15 text-primary" : "text-muted-foreground hover:bg-accent"
        }`}
      >
        <Icon className="w-5 h-5 shrink-0" aria-hidden />
        <span className="text-sm truncate">{name}</span>
        {isPlanned ? <SoonMarker /> : null}
      </button>
      {subItems && subItems.length > 0 && !isPlanned ? (
        <div className="ml-8 mt-1 space-y-1">
          {subItems.map((subItem) => (
            <button
              type="button"
              key={subItem.testId ?? subItem.path}
              onClick={() => onNavigate(subItem.path, subItem.action)}
              title={subItem.isPlanned ? `${subItem.name} (coming soon)` : subItem.name}
              aria-disabled={subItem.isBusy || undefined}
              aria-current={subItem.isActive ? "page" : undefined}
              data-testid={subItem.testId ? `${subItem.testId}--mobile` : undefined}
              className={`w-full flex items-center gap-2.5 p-2 rounded-md text-xs font-semibold transition-all ${
                subItem.isActive
                  ? "text-primary bg-primary/10"
                  : "text-muted-foreground hover:bg-accent"
              }`}
            >
              {subItem.icon && <subItem.icon className="w-3.5 h-3.5 shrink-0" aria-hidden />}
              <span className="truncate">{subItem.name}</span>
              {subItem.isPlanned ? <SoonMarker /> : null}
            </button>
          ))}
        </div>
      ) : null}
    </div>
  );
}
