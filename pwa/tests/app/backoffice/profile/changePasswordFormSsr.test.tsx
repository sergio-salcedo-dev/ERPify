import { describe, it, expect, vi } from "vitest";
import { render, screen } from "@testing-library/react";
import { renderToString } from "react-dom/server";

// Resolved only inside the submit handler, which this suite never reaches — the mock exists so the
// module graph loads without a live container on the server render path.
vi.mock("@/context/shared/dependency-injection/infrastructure/Container", () => ({
  container: { get: () => ({ changePassword: vi.fn() }) },
}));

import { ChangePasswordForm } from "@/app/backoffice/profile/_components/ChangePasswordForm";

/**
 * The window between the server render and the hydration commit is the whole subject here. In it React has
 * not wired `onSubmit` yet, so a submit — a click, or Enter in either field — is a NATIVE form submit: no
 * `method` means GET, no `action` means the current URL, and the two registered fields are appended as query
 * parameters. Both plaintexts would land in the address bar, the browser history, the `Referer` of the next
 * request and every access log in front of the app.
 *
 * The guard is that the server markup ships the submit already disabled (`useSyncExternalStore` reports
 * `false` until hydration), and these assertions are what stop it being removed as redundant.
 */
describe("ChangePasswordForm — server render, before hydration", () => {
  const html = (): string => renderToString(<ChangePasswordForm />);

  it("ships the submit disabled, so no unhydrated submit can fire the native GET", () => {
    const markup = html();

    const submit = markup.match(/<button[^>]*data-testid="change-password-form__submit"[^>]*>/);
    expect(submit).not.toBeNull();
    // The attribute, not the substring: the button's Tailwind classes carry `disabled:opacity-50`, so a
    // `toContain("disabled")` here passes whether or not the control is actually disabled.
    expect(submit?.[0]).toMatch(/\sdisabled=""/);
  });

  it("declares itself unhydrated, which is the flag the disabled state is derived from", () => {
    // Both halves are the assertion. The negative one alone is satisfied by DELETING the attribute — the same
    // shape of vacuous green this suite already had to correct once — and `data-hydrated` is not decorative:
    // the e2e specs wait on it. So the pair pins that it is absent before hydration and present after.
    expect(html()).not.toContain('data-hydrated="true"');

    render(<ChangePasswordForm />);

    expect(screen.getByTestId("change-password-form")).toHaveAttribute("data-hydrated", "true");
  });

  it("never nominates a GET target of its own", () => {
    const markup = html();
    const form = markup.match(/<form[^>]*data-testid="change-password-form"[^>]*>/);

    expect(form).not.toBeNull();
    expect(form?.[0]).not.toContain("action=");
    expect(form?.[0]).not.toContain('method="get"');
  });

  it("carries both credentials as named fields — which is why the disabled submit matters", () => {
    // The names are what a native GET would turn into `?currentPassword=…&newPassword=…`. Asserting they
    // are present keeps the guard above honest: if the fields ever stopped being named, this test would go
    // green for the wrong reason.
    const markup = html();

    expect(markup).toContain('name="currentPassword"');
    expect(markup).toContain('name="newPassword"');
  });
});
