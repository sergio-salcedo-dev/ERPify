"use client";

import { useId, useState } from "react";
import { LucideIcon, ChevronRight, ChevronDown } from "lucide-react";

interface SubItem {
  name: string;
  path: string;
  icon?: LucideIcon;
  /** Optional `data-testid` for the sub-item button. */
  testId?: string;
  /**
   * Forwarded verbatim to `onClick` so the consumer can act on what an entry means rather
   * than on where it points. This component never interprets it.
   */
  action?: "sign-out";
  /**
   * Marks the entry as already working. Rendered as `aria-disabled`, not `disabled`: the
   * click must still reach `onClick`, because the consumer's own in-flight guard is what
   * drops it — a view that swallowed the event instead would make that guard untestable.
   */
  isBusy?: boolean;
  /**
   * Whether this leaf holds the current route. Decided by the consumer, which knows which sibling
   * wins when several match by prefix; this component never reads the router itself.
   */
  isActive?: boolean;
  /** The entry leads to a module that is not built yet; rendered as a "Soon" marker. */
  isPlanned?: boolean;
}

interface SidebarItemProps {
  name: string;
  icon: LucideIcon;
  path: string;
  isActive: boolean;
  onClick: (path: string, action?: "sign-out") => void;
  subItems?: SubItem[];
  isCompact?: boolean;
  /** Optional `data-testid` for the top-level item button. */
  testId?: string;
  /** Every destination behind this entry is a module that is not built yet. */
  isPlanned?: boolean;
}

/**
 * Text, not an icon or a tint. The visible pill is short for the sighted scan; the accessible name
 * carries the full phrase, so a screen reader hears "Invoicing, coming soon" before choosing to go
 * there. The comma is load-bearing: adjacent spans are concatenated into the name with their edge
 * whitespace trimmed, so a leading space would yield "Invoicingcoming soon".
 */
function SoonMarker() {
  return (
    <>
      <span
        className="sidebar-item__soon border-border text-muted-foreground ml-auto shrink-0 rounded border px-1 text-2xs leading-4 font-medium"
        aria-hidden="true"
      >
        Soon
      </span>
      <span className="sr-only">, coming soon</span>
    </>
  );
}

function entryTitle(name: string, isPlanned: boolean | undefined): string {
  return isPlanned ? `${name} (coming soon)` : name;
}

export function SidebarItem({
  name,
  icon: Icon,
  path,
  isActive,
  onClick,
  subItems,
  isCompact = false,
  testId,
  isPlanned,
}: Readonly<SidebarItemProps>) {
  const hasSubItems = subItems !== undefined && subItems.length > 0;
  const subItemsId = useId();

  // A section holding the current route starts open — a deep link or a reload must show the
  // active leaf, not a collapsed parent — and opens again whenever the route enters it later
  // (adjusting state during render to avoid cascading renders).
  const [isOpen, setIsOpen] = useState(isActive && hasSubItems);
  const [prevIsActive, setPrevIsActive] = useState(isActive);
  if (isActive !== prevIsActive) {
    setPrevIsActive(isActive);
    if (isActive && hasSubItems) {
      setIsOpen(true);
    }
  }

  const togglesSubItems = hasSubItems && !isCompact;

  const handleItemClick = () => {
    if (togglesSubItems) {
      setIsOpen(!isOpen);
      return;
    }
    // No sub-items, or compact mode collapses the chevron interaction — fall
    // back to navigating to the main path.
    onClick(path);
  };

  return (
    <div className="sidebar-item-wrapper">
      <button
        type="button"
        onClick={handleItemClick}
        title={entryTitle(name, isPlanned)}
        data-testid={testId}
        aria-expanded={togglesSubItems ? isOpen : undefined}
        aria-controls={togglesSubItems && isOpen ? subItemsId : undefined}
        aria-current={isActive && !togglesSubItems ? "page" : undefined}
        className={`sidebar-item w-full flex items-center justify-between gap-2 p-2.5 rounded-md font-semibold transition-all group ${
          isActive
            ? "sidebar-item--active bg-primary/15 text-primary shadow-sm"
            : "text-muted-foreground hover:bg-accent hover:text-foreground"
        } ${isCompact ? "justify-center" : ""}`}
      >
        <div
          className={`sidebar-item__content flex min-w-0 flex-1 items-center ${isCompact ? "justify-center" : "gap-3"}`}
        >
          <Icon className="sidebar-item__icon w-5 h-5 shrink-0" aria-hidden />
          {!isCompact && <span className="sidebar-item__name text-sm truncate">{name}</span>}
          {!isCompact && isPlanned ? <SoonMarker /> : null}
        </div>
        {!isCompact && (
          <>
            {hasSubItems ? (
              <ChevronDown
                className={`sidebar-item__chevron w-3.5 h-3.5 shrink-0 transition-transform ${isOpen ? "rotate-180" : ""}`}
                aria-hidden
              />
            ) : (
              <ChevronRight
                className={`sidebar-item__chevron w-3.5 h-3.5 shrink-0 transition-transform ${isActive ? "translate-x-0.5" : "opacity-0 group-hover:opacity-100"}`}
                aria-hidden
              />
            )}
          </>
        )}
      </button>

      {hasSubItems && isOpen && !isCompact && (
        <div id={subItemsId} className="sidebar-item__sub-items ml-9 mt-1 space-y-1">
          {subItems.map((subItem) => {
            const SubIcon = subItem.icon;
            return (
              <button
                type="button"
                // Identity, never the label: a consumer that relabels an entry while it works
                // would otherwise change the key, and React would destroy the button the user
                // just activated — dropping focus to <body> and moving the new state onto a
                // different node, where no assistive technology reads it.
                key={subItem.testId ?? subItem.path}
                onClick={() => onClick(subItem.path, subItem.action)}
                title={entryTitle(subItem.name, subItem.isPlanned)}
                aria-disabled={subItem.isBusy || undefined}
                aria-current={subItem.isActive ? "page" : undefined}
                data-testid={subItem.testId}
                className={`sidebar-item__sub-item w-full flex items-center gap-2.5 p-2 rounded-md text-xs font-medium transition-all ${
                  subItem.isActive
                    ? "text-primary bg-primary/10"
                    : "text-muted-foreground hover:bg-accent hover:text-foreground"
                }`}
              >
                {SubIcon && <SubIcon className="w-3.5 h-3.5 shrink-0" aria-hidden />}
                <span className="truncate">{subItem.name}</span>
                {subItem.isPlanned ? <SoonMarker /> : null}
              </button>
            );
          })}
        </div>
      )}
    </div>
  );
}
