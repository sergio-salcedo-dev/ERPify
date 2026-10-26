"use client";

import type { MouseEvent, ReactNode } from "react";
import Link from "next/link";

interface NavEntryLinkProps {
  href: string;
  /**
   * Runs on a plain activation instead of the link's own navigation, so the consumer keeps its
   * guards (an in-flight sign-out, a drawer that must close) on the one path that has them.
   */
  onNavigate: () => void;
  /** An entry that performs an action rather than opening a page; it renders as a button. */
  isAction?: boolean;
  /** An action already in flight; rendered as `aria-disabled` so the consumer's guard still runs. */
  isBusy?: boolean;
  title: string;
  className: string;
  testId?: string;
  isCurrent?: boolean;
  children: ReactNode;
}

/** A click the browser should handle itself: a new tab, a new window, a download. */
function isModifiedClick(event: MouseEvent<HTMLAnchorElement>): boolean {
  return (
    event.defaultPrevented ||
    event.button !== 0 ||
    event.metaKey ||
    event.ctrlKey ||
    event.shiftKey ||
    event.altKey
  );
}

/**
 * A navigation entry that is a real link: it has an `href`, so it can be opened in a new tab,
 * middle-clicked, copied, and is announced as a link — the role a screen-reader user lists to find
 * where they can go. A plain click is handed to `onNavigate` rather than to the link, so the
 * consumer's navigation guards apply exactly as they do to every other entry; a modified click is
 * left to the browser. `prefetch` is off: forty sidebar entries mounted on every page would
 * otherwise each fetch their route ahead of any intent to visit it.
 *
 * An entry that performs an action (signing out) is not a place, so it stays a button.
 */
export function NavEntryLink({
  href,
  onNavigate,
  isAction = false,
  isBusy,
  title,
  className,
  testId,
  isCurrent = false,
  children,
}: Readonly<NavEntryLinkProps>) {
  if (isAction) {
    return (
      <button
        type="button"
        onClick={onNavigate}
        title={title}
        aria-disabled={isBusy || undefined}
        data-testid={testId}
        className={className}
      >
        {children}
      </button>
    );
  }
  return (
    <Link
      href={href}
      prefetch={false}
      onClick={(event) => {
        if (isModifiedClick(event)) return;
        event.preventDefault();
        onNavigate();
      }}
      title={title}
      aria-current={isCurrent ? "page" : undefined}
      data-testid={testId}
      className={className}
    >
      {children}
    </Link>
  );
}
