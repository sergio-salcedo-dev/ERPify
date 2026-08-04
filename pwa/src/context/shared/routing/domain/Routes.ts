/**
 * Top-level route paths for the PWA. They replace `"/"` / `"/backoffice"`
 * literals in shared infrastructure code (error pages, navigation guards,
 * fallbacks) so renames stay grep-able and typos surface at compile time.
 *
 * Only top-level entry points belong here — entity-scoped paths
 * (`/backoffice/banks/${id}`) live next to the use case that builds them.
 *
 * The matching TypeScript type is derived via
 * `(typeof Routes)[keyof typeof Routes]` so adding / renaming a value
 * forces every call site to update.
 */
export const Routes = {
  /** Public landing page. */
  HOME: "/",
  /** Public login page. */
  LOGIN: "/login",
  /** Public forgot-password page. */
  FORGOT_PASSWORD: "/forgot-password",
  /** Public reset-password page. */
  RESET_PASSWORD: "/reset-password",
  /** Public accept-invitation page — sets the credential that activates an invited account. */
  ACCEPT_INVITATION: "/accept-invitation",
  /** Authenticated BackOffice root — every `/backoffice/*` path lives under this prefix. */
  BACKOFFICE: "/backoffice",
  /**
   * The signed-in user's own account view. A top-level entry point in its own
   * right: the sidebar Account group, the top-bar account menu and the page
   * itself all address it, so the literal must not drift between them.
   */
  PROFILE: "/backoffice/profile",
  /** Public service status page (Atlassian-style). Unauthenticated, like {@link HOME}. */
  STATUS: "/status",
  /**
   * Dev / QA tools hub. Cross-referenced from the frontoffice navbar
   * and the backoffice sidebar (and from inside the dev-tools module
   * itself) so the literal `/dev-tools` doesn't drift across files.
   * The route is gated behind `isDevToolsAvailable()` and short-
   * circuited by the proxy in production.
   */
  DEV_TOOLS: "/backoffice/dev-tools",
} as const;
export type Routes = (typeof Routes)[keyof typeof Routes];
