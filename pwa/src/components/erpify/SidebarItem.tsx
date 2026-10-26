"use client";

import { useId, useState } from "react";
import { LucideIcon, ChevronRight, ChevronDown } from "lucide-react";
import { cn } from "@/components/cn";
import { NavEntryLink } from "./NavEntryLink";

interface SubItem<TAction extends string> {
  name: string;
  path: string;
  icon?: LucideIcon;
  /** Optional `data-testid` for the sub-item button. */
  testId?: string;
  /**
   * Forwarded verbatim to `onClick` so the consumer can act on what an entry means rather
   * than on where it points. This component never interprets it, so the vocabulary is the
   * consumer's type parameter rather than a literal named here.
   */
  action?: TAction;
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

interface SidebarItemProps<TAction extends string> {
  name: string;
  icon: LucideIcon;
  path: string;
  isActive: boolean;
  onClick: (path: string, action?: TAction) => void;
  subItems?: SubItem<TAction>[];
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

/**
 * The trailing glyph: a section's chevron turns with its open state, a leaf's arrow shows on hover
 * and stays nudged while the leaf is the current page.
 */
function SidebarChevron({
  hasSubItems,
  isOpen,
  isActive,
}: Readonly<{ hasSubItems: boolean; isOpen: boolean; isActive: boolean }>) {
  if (hasSubItems) {
    return (
      <ChevronDown
        className={cn(
          "sidebar-item__chevron w-3.5 h-3.5 shrink-0 transition-transform",
          isOpen && "rotate-180",
        )}
        aria-hidden
      />
    );
  }
  return (
    <ChevronRight
      className={cn(
        "sidebar-item__chevron w-3.5 h-3.5 shrink-0 transition-transform",
        isActive ? "translate-x-0.5" : "opacity-0 group-hover:opacity-100",
      )}
      aria-hidden
    />
  );
}

/** The icon, and beside it the name and "Soon" marker the compact rail has no room for. */
function SidebarItemContent({
  icon: Icon,
  name,
  isCompact,
  isPlanned,
}: Readonly<{ icon: LucideIcon; name: string; isCompact: boolean; isPlanned?: boolean }>) {
  return (
    <div
      className={cn(
        "sidebar-item__content flex min-w-0 flex-1 items-center",
        isCompact ? "justify-center" : "gap-3",
      )}
    >
      <Icon className="sidebar-item__icon w-5 h-5 shrink-0" aria-hidden />
      {!isCompact && <span className="sidebar-item__name text-sm truncate">{name}</span>}
      {!isCompact && isPlanned ? <SoonMarker /> : null}
    </div>
  );
}

function SidebarSubItem<TAction extends string>({
  subItem,
  onClick,
}: Readonly<{ subItem: SubItem<TAction>; onClick: SidebarItemProps<TAction>["onClick"] }>) {
  const SubIcon = subItem.icon;
  return (
    <NavEntryLink
      href={subItem.path}
      onNavigate={() => onClick(subItem.path, subItem.action)}
      isAction={subItem.action !== undefined}
      isBusy={subItem.isBusy}
      title={entryTitle(subItem.name, subItem.isPlanned)}
      isCurrent={subItem.isActive}
      testId={subItem.testId}
      className={cn(
        "sidebar-item__sub-item w-full flex items-center gap-2.5 p-2 rounded-md text-xs font-medium transition-all",
        subItem.isActive
          ? "text-primary bg-primary/10"
          : "text-muted-foreground hover:bg-accent hover:text-foreground",
      )}
    >
      {SubIcon && <SubIcon className="w-3.5 h-3.5 shrink-0" aria-hidden />}
      <span className="truncate">{subItem.name}</span>
      {subItem.isPlanned ? <SoonMarker /> : null}
    </NavEntryLink>
  );
}

/**
 * Generic over the consumer's action vocabulary, inferred from `subItems` and `onClick`: an entry
 * carrying an action the consumer's handler does not accept fails to compile. `never` applies only
 * when nothing names an action.
 */
export function SidebarItem<TAction extends string = never>({
  name,
  icon: Icon,
  path,
  isActive,
  onClick,
  subItems,
  isCompact = false,
  testId,
  isPlanned,
}: Readonly<SidebarItemProps<TAction>>) {
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

  const className = cn(
    "sidebar-item w-full flex items-center justify-between gap-2 p-2.5 rounded-md font-semibold transition-all group",
    isActive
      ? "sidebar-item--active bg-primary/15 text-primary shadow-sm"
      : "text-muted-foreground hover:bg-accent hover:text-foreground",
    isCompact && "justify-center",
  );
  const content = (
    <>
      <SidebarItemContent icon={Icon} name={name} isCompact={isCompact} isPlanned={isPlanned} />
      {!isCompact && (
        <SidebarChevron hasSubItems={hasSubItems} isOpen={isOpen} isActive={isActive} />
      )}
    </>
  );

  return (
    <div className="sidebar-item-wrapper">
      {/* An expanded section header opens and closes its leaves, which is an action rather than a
          place, so it is a button. Everything else — a leaf-less entry, or a section on the
          compact rail, where a click goes to its first page — is a link. */}
      {togglesSubItems ? (
        <button
          type="button"
          onClick={() => setIsOpen(!isOpen)}
          title={entryTitle(name, isPlanned)}
          data-testid={testId}
          aria-expanded={isOpen}
          aria-controls={isOpen ? subItemsId : undefined}
          className={className}
        >
          {content}
        </button>
      ) : (
        <NavEntryLink
          href={path}
          onNavigate={() => onClick(path)}
          title={entryTitle(name, isPlanned)}
          isCurrent={isActive}
          testId={testId}
          className={className}
        >
          {content}
        </NavEntryLink>
      )}

      {hasSubItems && isOpen && !isCompact && (
        <div id={subItemsId} className="sidebar-item__sub-items ml-9 mt-1 space-y-1">
          {subItems.map((subItem) => (
            <SidebarSubItem
              // Identity, never the label: a consumer that relabels an entry while it works
              // would otherwise change the key, and React would destroy the button the user
              // just activated — dropping focus to <body> and moving the new state onto a
              // different node, where no assistive technology reads it.
              key={subItem.testId ?? subItem.path}
              subItem={subItem}
              onClick={onClick}
            />
          ))}
        </div>
      )}
    </div>
  );
}
