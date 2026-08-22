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
import { hardNavigate } from "@/context/shared/navigation/infrastructure/hardNavigate";
import {
  claimDeparture,
  releaseDeparture,
  currentDeparture,
  DepartureReason,
} from "@/context/shared/navigation/application/departure";
import { toastNotifier } from "@/context/shared/notification/infrastructure/Toast";

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
 * Where the sign-out interaction is. `leaving` is the in-flight window; the other three are the
 * ways it can end with the user still here. They stay distinct because their BEHAVIOUR differs —
 * `superseded` must not release a departure it never won, and must not raise a toast under the
 * expiry curtain — not because they are different things to SAY. What the user is told is one
 * message; see {@link SIGN_OUT_UNFINISHED}.
 */
const SignOut = {
  IDLE: "idle",
  LEAVING: "leaving",
  REFUSED: "refused",
  STALLED: "stalled",
  SUPERSEDED: "superseded",
} as const;
type SignOut = (typeof SignOut)[keyof typeof SignOut];

/**
 * The one thing that is true in all three ways this window can end with the user still here, and
 * the only one of them they can perceive.
 *
 * Three states used to carry three sentences — a refusal, a navigation taking too long, and a
 * navigation another caller superseded. That is the difference seen from the code. From the chair
 * it is one event: they pressed Sign out and they are still here. Naming the mechanism
 * ("another redirect took over") explains our internals to someone who never asked, and the
 * distinction it draws is one no user can act on differently.
 *
 * What it deliberately does NOT claim, and this is the constraint the wording is built around:
 * that they are still signed IN. `logout()` revokes the server session before any of this and
 * swallows its own failures by contract, so the session may well be gone while the document
 * stayed — "we couldn't sign you out" would be a guess stated as a fact. "Didn't finish" is true
 * either way, and "try again" is safe either way, because a repeat sign-out on an
 * already-revoked session is a no-op that still leaves.
 *
 * Emptying a live region announces NOTHING — a `role="status"` speaks on insertion — so every
 * state that ends the window carries a message. Falling back to "" is what made the recovery path
 * silent once: the user heard "Signing out…", then nothing, while the affordance quietly reverted.
 */
const SIGN_OUT_UNFINISHED = "Sign-out didn't finish. Please try again.";

const SIGN_OUT_MESSAGE: Record<SignOut, string> = {
  [SignOut.IDLE]: "",
  [SignOut.LEAVING]: "Signing out…",
  [SignOut.REFUSED]: SIGN_OUT_UNFINISHED,
  [SignOut.STALLED]: SIGN_OUT_UNFINISHED,
  [SignOut.SUPERSEDED]: SIGN_OUT_UNFINISHED,
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

  // The mobile Sheet never strands <body>, so the effect above never fires for it: the item a
  // click lands on stays focused until the Sheet's own close (Base UI's modal-dialog default)
  // returns focus to the hamburger trigger, which — measured live — lands well after the route
  // has already changed. `handleNavigation` is the only path that closes the Sheet to go
  // somewhere, so it is the one place that can tell a navigating close from Escape/backdrop/the
  // X button, which must still return focus to the trigger (the correct default for a dialog
  // that closes without going anywhere). Read via the Sheet's own `finalFocus`, timed to when it
  // actually unmounts, rather than trying to race the route-focus effect above against an
  // animation neither controls.
  //
  // Armed only once navigation is certain, not on entry to `handleNavigation`: that function is
  // also the desktop sidebar's and the top-bar dropdown's handler, neither of which opens the
  // mobile Sheet, and armed-but-never-consumed would sit `true` until the Sheet next closes for
  // an unrelated reason (Escape, with no navigation) and misdirect that close's focus. `isLeaving`
  // gates two branches to a no-op return with the Sheet already closing — a click landing there
  // still closes the mobile Sheet without navigating anywhere, and must not claim otherwise.
  const closingSheetViaNavigationRef = useRef(false);

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
  // `SUPERSEDED` belongs here now, and its absence was a real gap rather than a nuance: it
  // rendered `sr-only` and `polite`, so the only person who learned the sign-out had not happened
  // was one using a screen reader. That was defensible while the report meant "someone else is
  // leaving with the document" — it is not, now that it arrives only once nobody left.
  const isSignOutFailure =
    signOut === SignOut.REFUSED || signOut === SignOut.STALLED || signOut === SignOut.SUPERSEDED;

  const handleNavigation = (path: string, action?: NavAction) => {
    // Read before closing: this is the only signal, once the Sheet is shut, of whether it WAS
    // the mobile Sheet that just closed (desktop's sidebar and the top-bar dropdown call this
    // same function and never open it).
    const closingMobileSheet = isSidebarOpen;
    setIsSidebarOpen(false);
    if (action === "sign-out") {
      // A second click POSTs a second revoke against a session the first one already
      // revoked, and schedules a second navigation behind it. Reachable for as long as the
      // first revoke is outstanding: the click closes the menu, so it needs the menu
      // reopened, which is exactly the window SIGN_OUT_BUDGET_MS bounds.
      if (isLeaving) return;
      if (closingMobileSheet) closingSheetViaNavigationRef.current = true;
      setSignOut(SignOut.LEAVING);
      // Claimed HERE, before anything else in this branch: the revoke below drops the session
      // cookie, so everything already in flight — a dashboard poll, a Mercure authorize, a list
      // refetch — 401s inside this window, and the transport bounces a 401 to
      // `/login?reason=session-expired` unless a departure is already claimed. That second
      // navigation generally wins, so a user who asked to sign out used to land on "session
      // expired". The transport's exemption for the revoke CALL never covered its neighbours.
      //
      // One claim, three readers: this single flight, `RequireAuth` (which must not race its own
      // redirect against a navigation already leaving) and `SessionExpiryCurtain` (which reads
      // the REASON, so a sign-out does not raise a screen saying the session expired). It is
      // module state rather than context because the third reader is a module-level function
      // inside an infrastructure adapter, which reaches no provider.
      const claimed = claimDeparture(DepartureReason.SIGN_OUT);
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
            if (failure === "superseded") {
              // Another hard navigation already owned the document, and it has now demonstrably
              // stayed — sign-out itself did not fail, it just wasn't the one leaving, and the
              // one that was is not leaving either. Reaching here at all means the window is
              // over, which is why releasing the affordance below is no longer early: while the
              // winner was still pending this callback had not been called yet. Not a failure of
              // this interaction, so no REFUSED/STALLED styling — but still a real, non-empty
              // live-region message: an adversarial pass caught the earlier silent-IDLE version
              // of this branch reproducing the exact defect the comment above already closed for
              // REFUSED/STALLED.
              setSignOut(SignOut.SUPERSEDED);
              // The toast is what REFUSED/STALLED lean on for the case their own subtree is
              // already unmounted — but the ONLY real caller that can supersede this one today
              // is the session-expiry bounce, which claims the departure below before it ever
              // reaches `hardNavigate`. So `claimed` is false here exactly when the reason
              // currently held is SESSION_EXPIRED, and winning that claim is what mounts
              // <SessionExpiryCurtain> over this entire subtree in the first place — by the
              // time this callback runs, the curtain's role="alert" already announced the
              // leaving state, and Sonner's viewport sits BELOW the curtain's z-index, so a
              // toast raised now would enqueue and never paint. Skip it in exactly that case;
              // keep it for a hypothetical future caller that supersedes this one without ever
              // claiming a departure of its own.
              // `error`, like the other two: one message at two severities is the same incoherence
              // one event at three messages was. The skip below is the part that stays.
              if (currentDeparture() !== DepartureReason.SESSION_EXPIRED) {
                toastNotifier.error(SIGN_OUT_MESSAGE[SignOut.SUPERSEDED]);
              }
              // Only the winner releases. A `claimed` of false means this attempt never owned
              // the departure to begin with — the session-expiry bounce already does, and it
              // is the one that will release it when ITS OWN navigation settles — which, by the
              // time this runs, it has.
              if (claimed) releaseDeparture();
              return;
            }
            const outcome = failure === "refused" ? SignOut.REFUSED : SignOut.STALLED;
            setSignOut(outcome);
            // The status region is no longer guaranteed to still be mounted by the time an
            // outcome is known (RequireAuth may already have unmounted it), so the outcome
            // itself is announced here instead — reaching the user regardless of what the
            // guarded subtree is doing.
            toastNotifier.error(SIGN_OUT_MESSAGE[outcome]);
            // Re-enables RequireAuth's own redirect: the sign-out navigation did not commit,
            // so the ordinary unauthenticated-route guard takes back over. Only the winner
            // releases — see the superseded branch above.
            if (claimed) releaseDeparture();
          });
        });
      return;
    }
    // Gated too: a sidebar click during the sign-out window would route somewhere the
    // pending navigation is about to tear down, losing whatever the user typed there. Only
    // menu-driven navigation reaches here — the logo and any link inside the page are next/link
    // and route on their own.
    if (isLeaving) return;
    if (closingMobileSheet) closingSheetViaNavigationRef.current = true;
    // A failure message describes one interaction, not the rest of the document's life: left
    // standing it stays in the accessibility tree through every later route change.
    setSignOut(SignOut.IDLE);
    router.push(path);
  };

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
    <RequireAuth>
      {/* The only signal that survives the click. Both menus close on activation, so on every
          path but the expanded sidebar the relabelled entry is unmounted before it can say
          anything — and meanwhile the in-flight guard drops every menu-driven navigation for up
          to SIGN_OUT_BUDGET_MS. This announces that window to assistive technology, which is the
          surface that otherwise gets nothing at all. It is `sr-only`, so it states the window
          without showing it: the sighted affordance is the relabelled entry, which survives on
          the expanded sidebar and not on the two menus that close over it. It only has to reach
          the pre-outcome WAIT state — the outcome itself is a toast, which does not depend on
          this guarded subtree still being mounted once it is known.
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
                <SheetContent
                  side="left"
                  className="bo-layout__sidebar-mobile p-0 w-72"
                  finalFocus={() => {
                    const viaNavigation = closingSheetViaNavigationRef.current;
                    closingSheetViaNavigationRef.current = false;
                    // `true` keeps Base UI's own default (return focus to the trigger) for
                    // Escape / backdrop / the X button — a real close, not a navigation. It is
                    // also the fallback if `<main>` were somehow not yet mounted, rather than
                    // handing Base UI a `null` whose meaning this prop never documents.
                    return viaNavigation ? (mainRef.current ?? true) : true;
                  }}
                >
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
                              data-testid={subItem.testId ? `${subItem.testId}--mobile` : undefined}
                              className={`w-full flex items-center gap-2.5 p-2 rounded-md text-xs font-semibold transition-all ${
                                pathname === subItem.path
                                  ? "text-primary bg-primary/10"
                                  : "text-muted-foreground hover:bg-accent"
                              }`}
                            >
                              {subItem.icon && <subItem.icon className="w-3.5 h-3.5" aria-hidden />}
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
                          key={entry.testId ?? entry.path}
                          onClick={() => handleNavigation(entry.path, entry.action)}
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
                          onClick={() => handleNavigation(accountLogout.path, accountLogout.action)}
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
