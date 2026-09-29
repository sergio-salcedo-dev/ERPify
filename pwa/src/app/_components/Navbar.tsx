import { useState } from "react";
import { Menu, X, Wrench } from "lucide-react";
import Link from "next/link";
import { Logo, ThemeToggle } from "@/components/erpify";
import { Button } from "@/components/ui/button";
import { useSession } from "@/context/shared/access/application/useSession";
import { AuthStatus } from "@/context/shared/access/infrastructure/ui/AuthProvider";
import { isDevToolsAvailable } from "@/context/shared/dev-tools/domain/isDevToolsAvailable";
import { safeHref } from "@/context/shared/navigation/domain/safeHref";
import { Routes } from "@/context/shared/routing/domain/Routes";

interface NavbarProps {
  goToBackoffice: () => void;
}

export function Navbar({ goToBackoffice }: Readonly<NavbarProps>) {
  const [isMenuOpen, setIsMenuOpen] = useState(false);
  const showDevTools = isDevToolsAvailable();
  const { status } = useSession();
  // "Sign in" is offered only once the session is known not to be an active one. While
  // hydrating it stays hidden, so an authenticated visitor never sees it flash on load.
  const showSignIn = status === AuthStatus.UNAUTHENTICATED;
  // `unavailable` means the server cannot tell whether anyone is signed in, and both entries
  // lead through the same outage: the sign-in form would be refused, and the back office
  // would bounce straight to the maintenance page. Offering neither is the honest state.
  const showBackoffice = status !== AuthStatus.UNAVAILABLE;

  return (
    <nav
      className="navbar bg-card border-b border-border sticky top-0 z-50"
      data-session-status={status}
    >
      <div className="navbar__container max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">
        <div className="navbar__inner flex justify-between h-16 items-center">
          <Logo
            href={safeHref(Routes.HOME)}
            variant="badge"
            size="lg"
            className="navbar__logo"
            textClassName="navbar__logo-text"
            iconClassName="navbar__logo-icon"
          />

          {/* Desktop Menu */}
          <div className="navbar__menu hidden md:flex items-center space-x-8">
            <Link
              href={safeHref(Routes.STATUS)}
              className="navbar__link text-muted-foreground hover:text-primary font-medium transition-colors"
              data-testid="navbar__link-status"
            >
              Status
            </Link>

            {showDevTools ? (
              <Link
                href={safeHref(Routes.DEV_TOOLS)}
                className="navbar__link navbar__link--dev-tools text-warning-strong hover:text-warning-strong/80 font-medium transition-colors inline-flex items-center gap-1.5"
                title="Internal QA / engineering tools (dev/test only)"
                data-testid="navbar__dev-tools-link"
              >
                <Wrench className="w-4 h-4" aria-hidden="true" />
                Dev Tools
              </Link>
            ) : null}

            <ThemeToggle testId="navbar__theme" className="navbar__theme" />

            {showSignIn ? (
              <Link
                href={safeHref(Routes.LOGIN)}
                className="navbar__link navbar__link--login text-foreground hover:text-primary font-medium transition-colors"
                data-testid="navbar__link-login"
              >
                Sign in
              </Link>
            ) : null}
            {status === AuthStatus.HYDRATING ? (
              // Holds the slot "Sign in" may take once the session resolves, so the items
              // beside it do not shift sideways when it appears for an anonymous visitor.
              <span
                aria-hidden="true"
                className="navbar__link navbar__link--login invisible font-medium"
              >
                Sign in
              </span>
            ) : null}

            {showBackoffice ? (
              <Button
                onClick={goToBackoffice}
                size="default"
                className="navbar__button rounded-full"
                data-testid="navbar__go-to-backoffice-button"
              >
                Backoffice
              </Button>
            ) : null}
          </div>

          {/* Mobile Menu Button */}
          <div className="navbar__mobile-toggle md:hidden flex items-center gap-1">
            <ThemeToggle testId="navbar__theme--mobile" className="navbar__theme--mobile" />
            <button
              type="button"
              onClick={() => setIsMenuOpen(!isMenuOpen)}
              className="p-2 text-muted-foreground"
              aria-label={isMenuOpen ? "Close navigation menu" : "Open navigation menu"}
              data-testid="navbar__mobile-menu-toggle"
            >
              {isMenuOpen ? <X /> : <Menu />}
            </button>
          </div>
        </div>
      </div>

      {/* Mobile Menu */}
      {isMenuOpen && (
        <div className="navbar__mobile-menu md:hidden bg-card border-b border-border px-4 pt-2 pb-6 space-y-4 animate-in fade-in-0 slide-in-from-top-2 duration-200">
          <Link
            href={safeHref(Routes.STATUS)}
            className="navbar__link block text-muted-foreground font-medium"
            data-testid="navbar__link-status--mobile"
          >
            Status
          </Link>
          {showDevTools ? (
            <Link
              href={safeHref(Routes.DEV_TOOLS)}
              className="navbar__link navbar__link--dev-tools text-warning-strong hover:text-warning-strong/80 font-medium inline-flex items-center gap-1.5"
              title="Internal QA / engineering tools (dev/test only)"
              data-testid="navbar__mobile-dev-tools-link"
            >
              <Wrench className="w-4 h-4" aria-hidden="true" />
              Dev Tools
            </Link>
          ) : null}
          {showSignIn ? (
            <Link
              href={safeHref(Routes.LOGIN)}
              className="navbar__link navbar__link--login block text-foreground font-medium"
              data-testid="navbar__link-login--mobile"
            >
              Sign in
            </Link>
          ) : null}
          {showBackoffice ? (
            <Button
              onClick={goToBackoffice}
              size="lg"
              className="navbar__button w-full rounded-xl"
              data-testid="navbar__go-to-backoffice-button--mobile"
            >
              Backoffice
            </Button>
          ) : null}
        </div>
      )}
    </nav>
  );
}
