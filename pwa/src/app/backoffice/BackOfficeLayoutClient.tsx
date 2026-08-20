"use client";

import { useEffect, useRef, useState } from "react";
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

const SIDEBAR_STORAGE_KEY = "erpify:sidebar-open";

// How long sign-out waits for the server revoke before leaving anyway. The revoke carries
// no AbortSignal, so without a budget a request that never settles leaves the user on a
// page that looks signed in and answers nothing — the one outcome worse than a stale cookie.
const REVOKE_BUDGET_MS = 3_000;

function afterMs(ms: number): { elapsed: Promise<void>; cancel: () => void } {
  let timer: ReturnType<typeof setTimeout>;
  const elapsed = new Promise<void>((resolve) => {
    timer = setTimeout(resolve, ms);
  });
  return { elapsed, cancel: () => clearTimeout(timer) };
}

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

  // Focus follows the route. Next calls `.focus()` on the first host node of the changed segment
  // (`layout-router.js`), and that node is the page's own root, which carries no `tabIndex` — so
  // the call is a no-op and focus stays on `<body>`: after a client navigation the next Tab
  // restarts at the skip link and walks the whole chrome before reaching content. `<main>` takes
  // the focus here instead of every page root taking a `tabIndex` of its own, which is also what
  // makes the skip link land (a fragment link moves focus only to a focusable target).
  //
  // Keyed on `usePathname()`, never on the full URL: the lists write their filters and cursor to
  // the query string, so keying on that would pull focus out of a filter input on every keystroke.
  //
  // A page that claimed the focus itself keeps it. The create routes' first field carries
  // `autoFocus`, which React applies in the commit phase — before this passive effect — so the
  // guard reads it as already placed and yields. Only a stranded `<body>` is corrected.
  //
  // A menu click therefore keeps the focus on the button that did it, which survives the
  // navigation: the next Tab continues from there, and a parent entry that just expanded its
  // sub-items keeps them next in the order. What strands `<body>` is a trigger that goes away —
  // an in-page link, the mobile drawer closing over itself, a redirect — and that is the case
  // this corrects. Moving focus on every navigation is the stronger reading of "focus follows
  // content"; it would cost that submenu behaviour, so it is a decision rather than an omission.
  const mainRef = useRef<HTMLElement | null>(null);
  const hasNavigatedRef = useRef(false);

  useEffect(() => {
    if (!hasNavigatedRef.current) {
      hasNavigatedRef.current = true;
      return;
    }
    const active = globalThis.document.activeElement;
    if (active !== null && active !== globalThis.document.body) return;
    mainRef.current?.focus({ preventScroll: true });
  }, [pathname]);

  const menuGroups = backofficeMenuGroups;

  // Sign-out is in flight. State rather than a ref because the click closes the menu, so a
  // second attempt needs it reopened — an asynchronous gap a re-render always wins.
  const [isLeaving, setIsLeaving] = useState(false);

  const handleNavigation = (path: string, action?: NavAction) => {
    setIsSidebarOpen(false);
    if (action === "sign-out") {
      // A second click POSTs a second revoke against a session the first one already
      // revoked, and schedules a second navigation behind it. Reachable for as long as the
      // first revoke is outstanding: the click closes the menu, so it needs the menu
      // reopened, which is exactly the window REVOKE_BUDGET_MS bounds.
      if (isLeaving) return;
      setIsLeaving(true);
      // `action` is what makes this sign out. The destination below is hard-coded to HOME and
      // this entry's own `path` is never read on this branch. Two tests hold the two together,
      // one per direction: the model guard asserts the entry's declared path, and the
      // per-surface sign-out cases assert where this branch actually lands.
      //
      // logout() revokes the server session (dropping its cookie) then clears client state; wait
      // up to REVOKE_BUDGET_MS for it so the cookie is normally gone before leaving. Then leave
      // the authenticated area with a full-document navigation rather than router.push: an SPA
      // push keeps this guarded subtree mounted, so RequireAuth observes the just-cleared session
      // mid-transition and redirects to /login before the push to HOME commits. A hard navigation
      // discards all in-memory client state and lands on the public landing unconditionally — and
      // it fires even if the server revoke failed. replace() rather than assign() so the
      // authenticated page it leaves is not one Back press away, where a bfcache restore would
      // put the previous user's data back on a shared machine.
      const budget = afterMs(REVOKE_BUDGET_MS);
      void Promise.race([logout(), budget.elapsed]).finally(() => {
        budget.cancel();
        try {
          // eslint-disable-next-line no-restricted-syntax
          globalThis.location.replace(Routes.HOME);
        } catch {
          // The document stayed, so sign-out must be attemptable again rather than wedged. Only
          // the budget-expired path can act on it: once logout() resolves it clears the session,
          // and the guarded subtree this menu lives in goes away with it.
          setIsLeaving(false);
        }
      });
      return;
    }
    // Gated too: a sidebar click during the sign-out window would route somewhere the
    // pending navigation is about to tear down, losing whatever the user typed there. Only
    // menu-driven navigation reaches here — the logo and any link inside the page are next/link
    // and route on their own.
    if (isLeaving) return;
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
  const accountItemWithState = {
    ...accountMenuItem,
    subItems: accountEntries.map((entry) => ({
      ...entry,
      name: entryLabel(entry),
      isBusy: isEntryLeaving(entry),
    })),
  };
  const accountEmail = session?.user.email ?? "";

  const isItemActive = (item: NavItem) => {
    if (pathname === item.path) return true;
    if (item.subItems) {
      return item.subItems.some((sub) => pathname === sub.path);
    }
    return false;
  };

  return (
    <RequireAuth>
      <TooltipProvider>
        {/* The only signal that survives the click. Both menus close on activation, so on every
            path but the expanded sidebar the relabelled entry is unmounted before it can say
            anything — and meanwhile the in-flight guard drops every menu-driven navigation for up
            to REVOKE_BUDGET_MS. This announces that window to assistive technology, which is the
            surface that otherwise gets nothing at all. It is `sr-only`, so it states the window
            without showing it: the sighted affordance is the relabelled entry, which survives on
            the expanded sidebar and not on the two menus that close over it.
            `<output>` carries the `status` role natively, so spelling it out would be redundant;
            `aria-live` is spelled out anyway, for the assistive technology that reads the attribute
            and not the implicit mapping. */}
        <output
          aria-live="polite"
          data-testid="bo-layout__leaving-status"
          className="bo-layout__leaving-status sr-only"
        >
          {isLeaving ? "Signing out…" : ""}
        </output>
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
                      {...item}
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
                {...accountItemWithState}
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
                                    key={subItem.name}
                                    onClick={navigateTo(subItem.path, subItem.action)}
                                    title={subItem.name}
                                    data-testid={
                                      subItem.testId ? `${subItem.testId}--mobile` : undefined
                                    }
                                    className={`w-full flex items-center gap-2.5 p-2 rounded-md text-xs font-semibold transition-all ${
                                      pathname === subItem.path
                                        ? "text-primary bg-primary/10"
                                        : "text-muted-foreground hover:bg-accent"
                                    }`}
                                  >
                                    {subItem.icon && <subItem.icon className="w-3.5 h-3.5" />}
                                    {subItem.name}
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
                              key={subItem.name}
                              onClick={() => handleNavigation(subItem.path, subItem.action)}
                              title={entryLabel(subItem)}
                              aria-disabled={isEntryLeaving(subItem) || undefined}
                              data-testid={subItem.testId ? `${subItem.testId}--mobile` : undefined}
                              className={`w-full flex items-center gap-2.5 p-2 rounded-md text-xs font-semibold transition-all ${
                                pathname === subItem.path
                                  ? "text-primary bg-primary/10"
                                  : "text-muted-foreground hover:bg-accent"
                              }`}
                            >
                              {subItem.icon && <subItem.icon className="w-3.5 h-3.5" />}
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
                title={isCompact ? "Expand sidebar (Ctrl/Cmd+B)" : "Collapse sidebar (Ctrl/Cmd+B)"}
                data-testid="bo-layout__topbar-toggle"
                onClick={() => setIsCompact((compact) => !compact)}
                className="bo-layout__topbar-toggle"
              >
                {isCompact ? (
                  <PanelLeftOpen className="size-4" aria-hidden />
                ) : (
                  <PanelLeftClose className="size-4" aria-hidden />
                )}
                <span className="sr-only">{isCompact ? "Expand sidebar" : "Collapse sidebar"}</span>
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
                          key={entry.name}
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
            <main
              id="main-content"
              ref={mainRef}
              tabIndex={-1}
              className="bo-layout__main flex-grow pt-14 outline-none focus-visible:outline-2 focus-visible:-outline-offset-2 focus-visible:outline-ring md:pt-0"
            >
              <div className="bo-layout__content mx-auto p-4 md:p-8">{children}</div>
            </main>
          </div>
        </div>
      </TooltipProvider>
    </RequireAuth>
  );
}
