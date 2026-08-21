"use client";

import { useEffect, useState } from "react";
import { useRouter, usePathname } from "next/navigation";
import { Menu, Bell, PanelLeftClose, PanelLeftOpen, Search } from "lucide-react";
import { Logo, MonogramAvatar, SidebarItem, ThemeToggle } from "@/components/erpify";
import { Button } from "@/components/ui/button";
import {
  DropdownMenu,
  DropdownMenuContent,
  DropdownMenuGroup,
  DropdownMenuItem,
  DropdownMenuLabel,
  DropdownMenuSeparator,
  DropdownMenuTrigger,
} from "@/components/ui/dropdown-menu";
import { Sheet, SheetContent, SheetHeader, SheetTitle, SheetTrigger } from "@/components/ui/sheet";
import { TooltipProvider } from "@/components/ui/tooltip";
import { sectionTitleFor } from "./_lib/sectionTitle";
import {
  backofficeMenuGroups,
  accountMenuItem,
  type NavItem,
  type NavSubItem,
  type NavAction,
} from "./_lib/backofficeMenu";
import { RequireAuth, DevSessionSwitcher } from "@/context/shared/access/infrastructure/ui";
import { useSession } from "@/context/shared/access/application/useSession";
import { isDevToolsAvailable } from "@/context/shared/dev-tools/domain/isDevToolsAvailable";
import { Routes } from "@/context/shared/routing/domain/Routes";
import { hardNavigate } from "@/context/shared/navigation/infrastructure/hardNavigate";

const SIDEBAR_STORAGE_KEY = "erpify:sidebar-open";

// How long sign-out waits for the server revoke before leaving anyway, handed to the
// transport rather than enforced here: a request that never settles leaves the user on a page
// that looks signed in and answers nothing, and "no request hangs forever" is an invariant of
// the transport, not of a layout. What stays this component's to decide is the NUMBER — how
// long *this* interaction is willing to wait — which is why it is passed in rather than
// inherited from the client's default.
const SIGN_OUT_BUDGET_MS = 3_000;

function afterMs(ms: number): { elapsed: Promise<void>; cancel: () => void } {
  let timer: ReturnType<typeof setTimeout>;
  const elapsed = new Promise<void>((resolve) => {
    timer = setTimeout(resolve, ms);
  });
  return { elapsed, cancel: () => clearTimeout(timer) };
}

/**
 * Where the sign-out interaction is. `leaving` is the in-flight window; the other two are the
 * ways it can end with the user still here, and they are distinguished because they are not
 * the same statement to make: a refusal is final, a navigation that never committed may yet.
 */
const SignOut = {
  IDLE: "idle",
  LEAVING: "leaving",
  REFUSED: "refused",
  STALLED: "stalled",
} as const;
type SignOut = (typeof SignOut)[keyof typeof SignOut];

/**
 * What the status region says in each state. Emptying a live region announces NOTHING — a
 * `role="status"` speaks on insertion — so every state that ends the window carries a
 * message of its own. Falling back to "" there is what made the recovery path silent: the
 * user heard "Signing out…", then nothing, while the visible affordance quietly reverted.
 */
const SIGN_OUT_MESSAGE: Record<SignOut, string> = {
  [SignOut.IDLE]: "",
  [SignOut.LEAVING]: "Signing out…",
  [SignOut.REFUSED]: "Sign-out did not complete. Please try again.",
  [SignOut.STALLED]: "Sign-out is taking longer than expected. You can try again.",
};

export default function BackOfficeLayoutClient({
  children,
}: Readonly<{ children: React.ReactNode }>) {
  const [isSidebarOpen, setIsSidebarOpen] = useState(false);
  const [isCompact, setIsCompact] = useState(() => {
    if (globalThis.window === undefined) return false;
    const stored = globalThis.localStorage.getItem(SIDEBAR_STORAGE_KEY);
    return stored === null ? false : stored !== "1";
  });
  const router = useRouter();
  const pathname = usePathname();
  const sectionTitle = sectionTitleFor(pathname);
  const { logout, session } = useSession();

  useEffect(() => {
    if (globalThis.window === undefined) return;
    globalThis.localStorage.setItem(SIDEBAR_STORAGE_KEY, isCompact ? "0" : "1");
  }, [isCompact]);

  useEffect(() => {
    function handleKey(event: KeyboardEvent): void {
      if ((event.metaKey || event.ctrlKey) && event.key.toLowerCase() === "b") {
        event.preventDefault();
        setIsCompact((compact) => !compact);
      }
    }
    globalThis.addEventListener("keydown", handleKey);
    return () => globalThis.removeEventListener("keydown", handleKey);
  }, []);

  const menuGroups = backofficeMenuGroups;

  // Sign-out is in flight. State rather than a ref because the click closes the menu, so a
  // second attempt needs it reopened — an asynchronous gap a re-render always wins.
  const [signOut, setSignOut] = useState<SignOut>(SignOut.IDLE);
  const isLeaving = signOut === SignOut.LEAVING;
  // A failure is an action error, so it is `assertive` per DESIGN.md and — unlike the busy
  // window, whose sighted affordance is the relabelled entry — it is VISIBLE. Every surface
  // but the expanded sidebar closes over that entry on activation, and on the sidebar a
  // silent revert cannot distinguish "failed" from "never started", so an sr-only-only
  // failure is the silent dismissal pwa/CLAUDE.md forbids.
  const isSignOutFailure = signOut === SignOut.REFUSED || signOut === SignOut.STALLED;

  const handleNavigation = (path: string, action?: NavAction) => {
    setIsSidebarOpen(false);
    if (action === "sign-out") {
      // A second click POSTs a second revoke against a session the first one already
      // revoked, and schedules a second navigation behind it. Reachable for as long as the
      // first revoke is outstanding: the click closes the menu, so it needs the menu
      // reopened, which is exactly the window SIGN_OUT_BUDGET_MS bounds.
      if (isLeaving) return;
      setSignOut(SignOut.LEAVING);
      // `action` is what makes this sign out. The destination below is hard-coded to HOME and
      // this entry's own `path` is never read on this branch. Two tests hold the two together,
      // one per direction: the model guard asserts the entry's declared path, and the
      // per-surface sign-out cases assert where this branch actually lands.
      //
      // logout() revokes the server session (dropping its cookie) then clears client state, and
      // gives the revoke SIGN_OUT_BUDGET_MS to answer so the cookie is normally gone before
      // leaving. Then leave the authenticated area with a full-document navigation rather than
      // router.push: an SPA push keeps this guarded subtree mounted, so RequireAuth observes the
      // just-cleared session mid-transition and redirects to /login before the push to HOME
      // commits. A hard navigation discards all in-memory client state and lands on the public
      // landing unconditionally — and it fires even if the server revoke failed.
      // The budget is raced here AND handed to the transport, and the two are not
      // alternatives. The transport bound covers a REQUEST; this one covers the OPERATION,
      // and only the second keeps the interaction from being pinned on something that is not
      // a fetch. It is also what makes the recovery below reachable at all: logout() clears
      // the session in its own `finally`, and the guarded subtree this menu lives in — the
      // status region included — goes away with it. Waiting for logout() to settle before
      // navigating therefore guarantees there is nothing left to announce into. When the
      // budget wins instead, the session is still live, the subtree is still mounted, and a
      // navigation that does not commit is exactly the case the recovery states.
      const budget = afterMs(SIGN_OUT_BUDGET_MS);
      void Promise.race([logout(SIGN_OUT_BUDGET_MS), budget.elapsed])
        .catch(() => {
          // logout() swallows its own failures by contract; this is the belt for one that stops
          // doing so, because a rejection here would otherwise surface as an unhandled rejection
          // *after* the navigation below has already been scheduled.
        })
        .finally(() => {
          budget.cancel();
          // Both ways the document can stay are handled, not just the one that raises: a
          // sandboxed navigable IGNORES a navigation silently, and keying recovery on the throw
          // left `isLeaving` latched for the life of the document — every menu-driven navigation
          // dropped from then on, with an sr-only string as the only feedback.
          hardNavigate(Routes.HOME, (failure) => {
            setSignOut(failure === "refused" ? SignOut.REFUSED : SignOut.STALLED);
          });
        });
      return;
    }
    // Gated too: a sidebar click during the sign-out window would route somewhere the
    // pending navigation is about to tear down, losing whatever the user typed there. Only
    // menu-driven navigation reaches here — the logo and any link inside the page are next/link
    // and route on their own.
    if (isLeaving) return;
    // A failure message describes one interaction, not the rest of the document's life: left
    // standing it stays in the accessibility tree through every later route change.
    setSignOut(SignOut.IDLE);
    router.push(path);
  };

  const navigateTo = (path: string, action?: NavAction) => () => handleNavigation(path, action);

  // The top-bar menu mirrors the sidebar's Account group rather than declaring its own
  // entries, so the two can never drift. Logout is split out: it is the only entry whose
  // target leaves the back office, and it reads as destructive.
  const accountEntries = accountMenuItem.subItems ?? [];
  const accountLinks = accountEntries.filter((entry) => entry.action !== "sign-out");
  const accountLogout = accountEntries.find((entry) => entry.action === "sign-out");

  // One derivation, so the entry cannot read as busy on one surface and idle on another. It is
  // only *visible* on the expanded sidebar, though: clicking closes the dropdown and the mobile
  // drawer, so on those paths the entry is unmounted before it could show anything. That is what
  // the status region below is for — it survives the menu closing, and no attribute on an element
  // that no longer exists can announce anything.
  const isEntryLeaving = (entry: NavSubItem) => isLeaving && entry.action === "sign-out";
  const entryLabel = (entry: NavSubItem) => (isEntryLeaving(entry) ? "Signing out…" : entry.name);
  // Applied to EVERY parent, not only the account one. `action` lives on NavSubItem, which
  // every group shares, and SidebarItem forwards it — so a group sub-item that ever carries
  // the intent would sign the user out while keeping its idle label and staying operable.
  // Handing the derivation to one parent and the raw model to the rest is the same
  // half-support this diff removes from the mobile drawer.
  const withEntryState = (item: NavItem): NavItem => ({
    ...item,
    subItems: item.subItems?.map((entry) => ({
      ...entry,
      name: entryLabel(entry),
      isBusy: isEntryLeaving(entry),
    })),
  });
  const accountEmail = session?.user.email ?? "";

  const isItemActive = (item: NavItem) => {
    if (pathname === item.path) return true;
    if (item.subItems) {
      return item.subItems.some((sub) => pathname === sub.path);
    }
    return false;
  };

  return (
    <>
      {/* The only signal that survives the click. Both menus close on activation, so on every
          path but the expanded sidebar the relabelled entry is unmounted before it can say
          anything — and meanwhile the in-flight guard drops every menu-driven navigation for up
          to SIGN_OUT_BUDGET_MS. This announces that window to assistive technology, which is the
          surface that otherwise gets nothing at all. It is `sr-only`, so it states the window
          without showing it: the sighted affordance is the relabelled entry, which survives on
          the expanded sidebar and not on the two menus that close over it.
          `<output>` carries the `status` role natively, so spelling it out would be redundant;
          `aria-live` is spelled out anyway, for the assistive technology that reads the attribute
          and not the implicit mapping. */}
      <output
        aria-live={isSignOutFailure ? "assertive" : "polite"}
        data-testid="bo-layout__leaving-status"
        className={
          isSignOutFailure
            ? "bo-layout__leaving-status border-destructive/30 bg-destructive/10 text-destructive fixed top-4 left-1/2 z-60 -translate-x-1/2 rounded-md border px-3 py-2 text-sm font-medium shadow-sm"
            : "bo-layout__leaving-status sr-only"
        }
      >
        {SIGN_OUT_MESSAGE[signOut]}
      </output>
      <RequireAuth>
        <TooltipProvider>
          <div className="bo-layout min-h-screen bg-background flex font-sans">
            <a
              href="#main-content"
              className="bo-layout__skip-link bg-primary text-primary-foreground sr-only z-50 px-3 py-2 focus:not-sr-only focus:absolute focus:top-2 focus:left-2 focus:rounded-md"
            >
              Skip to main content
            </a>

            {/* Sidebar Desktop */}
            <aside
              data-sidebar-open={!isCompact}
              className={`bo-layout__sidebar hidden md:flex flex-col bg-card border-r border-border sticky top-0 h-screen shadow-sm transition-all duration-300 ${
                isCompact ? "w-20" : "w-64"
              }`}
            >
              <div className="bo-layout__sidebar-header flex items-center border-b border-border h-16 px-4">
                {!isCompact && (
                  <Logo
                    href="/backoffice"
                    variant="badge"
                    size="md"
                    className="bo-layout__logo"
                    iconClassName="bo-layout__logo-icon"
                    textClassName="bo-layout__logo-text"
                  />
                )}
                {isCompact && (
                  <Logo
                    href="/backoffice"
                    variant="badge"
                    size="md"
                    iconOnly
                    className="bo-layout__logo bo-layout__logo--compact mx-auto"
                    iconClassName="bo-layout__logo-icon"
                  />
                )}
              </div>

              <nav className="bo-layout__sidebar-nav flex-grow p-3 space-y-6 overflow-y-auto">
                {menuGroups.map((group) => (
                  <div key={group.label} className="bo-layout__nav-group space-y-1">
                    {!isCompact && (
                      <p className="bo-layout__nav-label text-[10px] font-semibold text-muted-foreground uppercase tracking-widest px-3 mb-2">
                        {group.label}
                      </p>
                    )}
                    {group.items.map((item) => (
                      <SidebarItem
                        key={item.name}
                        {...withEntryState(item)}
                        isActive={isItemActive(item)}
                        onClick={handleNavigation}
                        isCompact={isCompact}
                      />
                    ))}
                  </div>
                ))}
              </nav>

              {/* User Profile at bottom */}
              <div className="bo-layout__footer p-3 border-t border-border">
                {!isCompact && (
                  <p className="bo-layout__nav-label text-[10px] font-semibold text-muted-foreground uppercase tracking-widest px-3 mb-2">
                    Account
                  </p>
                )}
                <SidebarItem
                  {...withEntryState(accountMenuItem)}
                  isActive={isItemActive(accountMenuItem)}
                  onClick={handleNavigation}
                  isCompact={isCompact}
                />
              </div>
            </aside>

            {/* Mobile Header */}
            <div className="bo-layout__header-mobile md:hidden fixed top-0 left-0 right-0 bg-card border-b border-border h-14 flex items-center justify-between px-4 z-50">
              <Logo
                href="/backoffice"
                variant="badge"
                size="md"
                className="bo-layout__logo-mobile"
                iconClassName="bo-layout__logo-icon"
                textClassName="bo-layout__logo-text"
              />
              <div className="bo-layout__header-mobile-actions flex items-center gap-1">
                <ThemeToggle testId="bo-layout__header-mobile-theme" />
                <Sheet open={isSidebarOpen} onOpenChange={setIsSidebarOpen}>
                  <SheetTrigger
                    render={
                      <button
                        type="button"
                        aria-label="Open navigation menu"
                        className="bo-layout__toggle-mobile p-2 text-foreground"
                      >
                        <Menu className="w-6 h-6" aria-hidden />
                      </button>
                    }
                  />
                  <SheetContent side="left" className="bo-layout__sidebar-mobile p-0 w-72">
                    <SheetHeader className="bo-layout__sidebar-mobile-header p-4 flex flex-row items-center justify-between border-b border-border">
                      <SheetTitle className="hidden">Navigation Menu</SheetTitle>
                      <Logo
                        href="/backoffice"
                        variant="badge"
                        size="md"
                        className="bo-layout__logo-mobile"
                        iconClassName="bo-layout__logo-icon"
                        textClassName="bo-layout__logo-text"
                      />
                    </SheetHeader>
                    <nav className="bo-layout__sidebar-mobile-nav p-4 space-y-6 overflow-y-auto h-[calc(100vh-64px)]">
                      {menuGroups.map((group) => (
                        <div key={group.label} className="bo-layout__mobile-group space-y-1">
                          <p className="text-[10px] font-semibold text-muted-foreground uppercase tracking-widest px-4 mb-2">
                            {group.label}
                          </p>
                          {group.items.map((item) => (
                            <div key={item.name} className="bo-layout__sidebar-mobile-item-wrapper">
                              <button
                                type="button"
                                onClick={() => handleNavigation(item.path)}
                                title={item.name}
                                data-testid={item.testId ? `${item.testId}--mobile` : undefined}
                                className={`bo-layout__sidebar-mobile-link w-full flex items-center gap-3 p-3 rounded-md font-semibold transition-all ${
                                  pathname === item.path
                                    ? "bg-primary/15 text-primary"
                                    : "text-muted-foreground hover:bg-accent"
                                }`}
                              >
                                <item.icon className="w-5 h-5" />
                                <span className="text-sm">{item.name}</span>
                              </button>
                              {item.subItems && (
                                <div className="ml-8 mt-1 space-y-1">
                                  {item.subItems.map((subItem) => (
                                    <button
                                      type="button"
                                      // Identity, never the label — see the account group below.
                                      key={subItem.testId ?? subItem.path}
                                      onClick={navigateTo(subItem.path, subItem.action)}
                                      title={entryLabel(subItem)}
                                      aria-disabled={isEntryLeaving(subItem) || undefined}
                                      data-testid={
                                        subItem.testId ? `${subItem.testId}--mobile` : undefined
                                      }
                                      className={`w-full flex items-center gap-2.5 p-2 rounded-md text-xs font-semibold transition-all ${
                                        pathname === subItem.path
                                          ? "text-primary bg-primary/10"
                                          : "text-muted-foreground hover:bg-accent"
                                      }`}
                                    >
                                      {subItem.icon && (
                                        <subItem.icon className="w-3.5 h-3.5" aria-hidden />
                                      )}
                                      {entryLabel(subItem)}
                                    </button>
                                  ))}
                                </div>
                              )}
                            </div>
                          ))}
                        </div>
                      ))}

                      <div className="bo-layout__mobile-group space-y-1 pt-4 border-t border-border">
                        <p className="text-[10px] font-semibold text-muted-foreground uppercase tracking-widest px-4 mb-2">
                          Account
                        </p>
                        <div className="bo-layout__sidebar-mobile-item-wrapper">
                          <button
                            type="button"
                            onClick={() => handleNavigation(accountMenuItem.path)}
                            title={accountMenuItem.name}
                            className={`bo-layout__sidebar-mobile-link w-full flex items-center gap-3 p-3 rounded-md font-semibold transition-all ${
                              pathname === accountMenuItem.path
                                ? "bg-primary/15 text-primary"
                                : "text-muted-foreground hover:bg-accent"
                            }`}
                          >
                            <accountMenuItem.icon className="w-5 h-5" />
                            <span className="text-sm">{accountMenuItem.name}</span>
                          </button>
                          <div className="ml-8 mt-1 space-y-1">
                            {accountMenuItem.subItems?.map((subItem) => (
                              <button
                                type="button"
                                // Identity, never the label: this site relabels the entry it is
                                // rendering, and a key derived from the label would change with it —
                                // React destroys the button the user just activated, focus falls to
                                // <body>, and the new state lands on a node nothing is watching.
                                key={subItem.testId ?? subItem.path}
                                onClick={() => handleNavigation(subItem.path, subItem.action)}
                                title={entryLabel(subItem)}
                                aria-disabled={isEntryLeaving(subItem) || undefined}
                                data-testid={
                                  subItem.testId ? `${subItem.testId}--mobile` : undefined
                                }
                                className={`w-full flex items-center gap-2.5 p-2 rounded-md text-xs font-semibold transition-all ${
                                  pathname === subItem.path
                                    ? "text-primary bg-primary/10"
                                    : "text-muted-foreground hover:bg-accent"
                                }`}
                              >
                                {subItem.icon && (
                                  <subItem.icon className="w-3.5 h-3.5" aria-hidden />
                                )}
                                {entryLabel(subItem)}
                              </button>
                            ))}
                          </div>
                        </div>
                      </div>
                    </nav>
                  </SheetContent>
                </Sheet>
              </div>
            </div>

            {/* Main column: desktop top bar + scrollable content */}
            <div className="bo-layout__column flex min-w-0 flex-1 flex-col">
              <header className="bo-layout__topbar hidden md:flex items-center gap-3 bg-card border-b border-border h-16 px-4">
                <Button
                  type="button"
                  variant="ghost"
                  size="icon-sm"
                  aria-label={isCompact ? "Expand sidebar" : "Collapse sidebar"}
                  aria-expanded={!isCompact}
                  title={
                    isCompact ? "Expand sidebar (Ctrl/Cmd+B)" : "Collapse sidebar (Ctrl/Cmd+B)"
                  }
                  data-testid="bo-layout__topbar-toggle"
                  onClick={() => setIsCompact((compact) => !compact)}
                  className="bo-layout__topbar-toggle"
                >
                  {isCompact ? (
                    <PanelLeftOpen className="size-4" aria-hidden />
                  ) : (
                    <PanelLeftClose className="size-4" aria-hidden />
                  )}
                  <span className="sr-only">
                    {isCompact ? "Expand sidebar" : "Collapse sidebar"}
                  </span>
                </Button>

                <span
                  data-testid="bo-layout__topbar-title"
                  className="bo-layout__topbar-title text-foreground text-sm font-semibold truncate"
                >
                  {sectionTitle}
                </span>

                <div className="bo-layout__topbar-actions ml-auto flex items-center gap-1">
                  {isDevToolsAvailable() ? <DevSessionSwitcher /> : null}
                  <ThemeToggle testId="bo-layout__topbar-theme" />
                  <Button
                    type="button"
                    variant="ghost"
                    size="icon-sm"
                    aria-label="Search"
                    title="Search"
                    data-testid="bo-layout__topbar-search"
                    className="bo-layout__topbar-search"
                  >
                    <Search className="size-4" aria-hidden />
                    <span className="sr-only">Search</span>
                  </Button>
                  <Button
                    type="button"
                    variant="ghost"
                    size="icon-sm"
                    aria-label="Notifications"
                    title="Notifications"
                    data-testid="bo-layout__topbar-notifications"
                    className="bo-layout__topbar-notifications relative"
                  >
                    <Bell className="size-4" aria-hidden />
                    <span
                      className="bo-layout__topbar-badge absolute right-1.5 top-1.5 size-1.5 rounded-full bg-primary"
                      aria-hidden
                    />
                    <span className="sr-only">Notifications</span>
                  </Button>
                  <DropdownMenu>
                    <DropdownMenuTrigger
                      render={
                        <Button
                          type="button"
                          variant="ghost"
                          size="icon-sm"
                          aria-label="Account menu"
                          title="Account menu"
                          data-testid="bo-layout__topbar-account"
                          className="bo-layout__topbar-account"
                        >
                          {/* The monogram is aria-hidden by contract, so the trigger keeps its
                            own static name in aria-label and the sr-only fallback. It is fed the
                            local part because `initials()` reads a single-word input as a name and
                            takes its first two characters — on a whole address that yields "A@". */}
                          <MonogramAvatar
                            name={accountEmail.split("@")[0]}
                            className="size-6 rounded-md text-[10px]"
                          />
                          <span className="sr-only">Account menu</span>
                        </Button>
                      }
                    />
                    <DropdownMenuContent
                      align="end"
                      className="w-56"
                      data-testid="bo-layout__account-menu"
                    >
                      <DropdownMenuGroup>
                        <DropdownMenuLabel className="truncate" title={accountEmail}>
                          {accountEmail}
                        </DropdownMenuLabel>
                        {accountLinks.map((entry) => (
                          <DropdownMenuItem
                            key={entry.testId ?? entry.path}
                            onClick={navigateTo(entry.path, entry.action)}
                            title={entry.name}
                            data-testid={entry.testId ? `${entry.testId}--menu` : undefined}
                          >
                            {entry.icon ? <entry.icon className="size-4" aria-hidden /> : null}
                            {entry.name}
                          </DropdownMenuItem>
                        ))}
                      </DropdownMenuGroup>
                      {accountLogout ? (
                        <>
                          <DropdownMenuSeparator />
                          <DropdownMenuItem
                            variant="destructive"
                            onClick={navigateTo(accountLogout.path, accountLogout.action)}
                            title={entryLabel(accountLogout)}
                            aria-disabled={isEntryLeaving(accountLogout) || undefined}
                            data-testid={
                              accountLogout.testId ? `${accountLogout.testId}--menu` : undefined
                            }
                          >
                            {accountLogout.icon ? (
                              <accountLogout.icon className="size-4" aria-hidden />
                            ) : null}
                            {entryLabel(accountLogout)}
                          </DropdownMenuItem>
                        </>
                      ) : null}
                    </DropdownMenuContent>
                  </DropdownMenu>
                </div>
              </header>

              {/* No overflow on <main>: the window is the scroll container (the
                column grows with content), and an overflow value here would
                re-scope every descendant `position: sticky` to a scrollport
                that never scrolls — breaking e.g. the banks bulk bar. Wide
                content (tables) brings its own overflow-x wrapper. */}
              <main id="main-content" className="bo-layout__main flex-grow pt-14 md:pt-0">
                <div className="bo-layout__content mx-auto p-4 md:p-8">{children}</div>
              </main>
            </div>
          </div>
        </TooltipProvider>
      </RequireAuth>
    </>
  );
}
