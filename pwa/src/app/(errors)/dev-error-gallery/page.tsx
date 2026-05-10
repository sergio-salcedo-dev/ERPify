// Visual inventory of every PWA error surface, linked from `/dev-tools`.
// Hidden from production via `isDevToolsAvailable()` (same gating model as
// the parent dev-tools hub and the `/dev-throw` fixture) — `notFound()`
// kicks in for any production user that types the URL by hand.
//
// To add a new error surface to the gallery, drop an entry into
// `NAVIGABLE_ROUTES` or `PROBLEM_FIXTURES` below.

import Link from "next/link";
import { notFound } from "next/navigation";
import { ProblemDisplay } from "@/components/erpify";
import type { ProblemDetails } from "@/context/shared/domain/ProblemDetails";
import { Routes } from "@/context/shared/domain/types/routes";
import { isDevToolsAvailable } from "@/context/shared/dev-tools/domain/isDevToolsAvailable";
import { buttonVariants } from "@/components/ui/button-variants";
import { cn } from "@/lib/utils";

interface RouteCase {
  label: string;
  url: string;
  description: string;
}

const NAVIGABLE_ROUTES: ReadonlyArray<RouteCase> = [
  {
    label: "404 — Page not found",
    url: "/this-route-does-not-exist",
    description: "Renders `app/not-found.tsx` for any unmatched URL.",
  },
  {
    label: "401 — Sign in required",
    url: "/unauthenticated",
    description: "Navigable info page; primary CTA is `Sign in`.",
  },
  {
    label: "403 — Access denied",
    url: "/unauthorized",
    description: "Navigable info page for permission failures.",
  },
  {
    label: "503 — Maintenance",
    url: "/maintenance",
    description: "Planned downtime / deployment window landing.",
  },
  {
    label: "429 — Rate limited",
    url: "/rate-limited",
    description: "Throttling / quota exceeded landing.",
  },
  {
    label: "Offline (PWA)",
    url: "/offline",
    description: "Network unreachable fallback for the service worker.",
  },
  {
    label: "500 — error.tsx boundary",
    url: "/dev-throw",
    description:
      "`/dev-throw` server-throws and the segment-level `error.tsx` catches it. Verifies the dev-mode `error.message` block + green-check digest copy.",
  },
  {
    label: "404 inside /backoffice — pathname-aware CTA",
    url: "/backoffice/this-segment-does-not-exist",
    description:
      "Same `not-found.tsx` UI but `<ErrorActions>` flips the primary CTA to `Return to BackOffice`.",
  },
];

interface ProblemCase {
  label: string;
  description: string;
  problem: ProblemDetails;
}

function makeProblem(
  overrides: Partial<ProblemDetails> & Pick<ProblemDetails, "type" | "title" | "status">,
): ProblemDetails {
  return {
    instance: "01926e7e-7b8a-7c4e-9f31-a2b7d1e4f5c6",
    "correlation-id": "01926e7e-7b8a-7c4e-9f30-000000000001",
    ...overrides,
  };
}

const PROBLEM_FIXTURES: ReadonlyArray<ProblemCase> = [
  {
    label: "400 — bad request",
    description: "Malformed request envelope. Warning tone, no violations.",
    problem: makeProblem({
      type: "bad-request",
      title: "Bad request",
      status: 400,
      detail: "The request payload was malformed and could not be parsed.",
    }),
  },
  {
    label: "401 — unauthenticated",
    description: "Missing / invalid credentials. LogIn icon.",
    problem: makeProblem({
      type: "unauthenticated",
      title: "Authentication required",
      status: 401,
      detail: "Your session has expired — please sign in again.",
    }),
  },
  {
    label: "403 — forbidden",
    description: "Authenticated but lacks permission. Lock icon.",
    problem: makeProblem({
      type: "forbidden",
      title: "Access denied",
      status: 403,
      detail: "You don't have permission to perform this action.",
    }),
  },
  {
    label: "404 — not found",
    description: "Resource doesn't exist. FileQuestion icon.",
    problem: makeProblem({
      type: "bank-not-found",
      title: "Bank not found",
      status: 404,
      detail: "No bank exists with that identifier.",
    }),
  },
  {
    label: "409 — conflict",
    description: "Concurrent edit / unique-constraint clash.",
    problem: makeProblem({
      type: "bank-short-name-already-in-use",
      title: "Short name already in use",
      status: 409,
      detail: "Another bank already uses that short name.",
    }),
  },
  {
    label: "422 — validation with violations",
    description: "Per-field violations rendered as a bullet list.",
    problem: makeProblem({
      type: "validation-failed",
      title: "Validation failed",
      status: 422,
      detail: "Some fields didn't pass server-side validation.",
      violations: [
        { field: "name", message: "must not be blank", code: "NotBlank" },
        { field: "shortName", message: "must be at most 10 characters", code: "Length" },
        { field: "iban", message: "invalid format", code: "Iban" },
      ],
    }),
  },
  {
    label: "429 — rate limited",
    description: "Hourglass icon, warning tone.",
    problem: makeProblem({
      type: "rate-limited",
      title: "Too many requests",
      status: 429,
      detail: "Slow down — try again in a few seconds.",
    }),
  },
  {
    label: "500 — server error WITH `debug` block",
    description:
      "Includes the API `debug` extension (exception class, file:line, previous chain). The block is gated to dev/test only — verify it disappears in a `next start` build.",
    problem: makeProblem({
      type: "unhandled-exception",
      title: "Internal server error",
      status: 500,
      detail: "The server hit an unexpected error.",
      debug: {
        exception_class: "Exception",
        message: "aaaaaaaaaaaaa",
        file: "/app/src/Backoffice/Bank/Infrastructure/Controller/BankSearchController.php",
        line: 41,
        previous_chain: [
          {
            exception_class: "RuntimeException",
            message: "while resolving BankRepository",
            file: "/app/vendor/foo/bar.php",
            line: 12,
          },
        ],
      },
    }),
  },
  {
    label: "503 — service unavailable",
    description: "Wrench icon, warning tone — used during deploys.",
    problem: makeProblem({
      type: "service-unavailable",
      title: "Service temporarily unavailable",
      status: 503,
      detail: "We're deploying improvements — back shortly.",
    }),
  },
  {
    label: "0 — synthetic 'no response' (network blip)",
    description: "Falls back to `No response` text instead of a numeric pill.",
    problem: makeProblem({
      type: "network-error",
      title: "Could not reach the server",
      status: 0,
      detail: "Check your connection and try again.",
    }),
  },
];

export const metadata = {
  title: "Error Gallery · Erpify (dev/test only)",
  description: "Visual inventory of every PWA error surface. Hidden in production.",
};

export default function DevErrorGalleryPage() {
  if (!isDevToolsAvailable()) {
    notFound();
  }

  return (
    <div className="dev-error-gallery bg-background min-h-screen" data-testid="dev-error-gallery">
      <div className="mx-auto max-w-5xl px-4 py-8 sm:px-6 sm:py-12 lg:px-8">
        <header className="dev-error-gallery__header border-warning/40 bg-warning/10 mb-8 rounded-md border p-4 sm:p-5">
          <p className="text-warning text-xs font-semibold tracking-wider uppercase">
            Dev / test only · linked from{" "}
            <Link
              href="/dev-tools"
              className="underline-offset-2 hover:underline"
              data-testid="dev-error-gallery__back-to-tools"
            >
              /dev-tools
            </Link>
          </p>
          <h1 className="text-foreground mt-1 text-2xl font-semibold tracking-tight sm:text-3xl">
            Error gallery
          </h1>
          <p className="text-muted-foreground mt-2 text-sm sm:text-base">
            Visual inventory of every error surface the PWA ships. The route is gated by{" "}
            <code className="bg-muted rounded px-1 py-0.5 font-mono text-xs">
              isDevToolsAvailable()
            </code>{" "}
            so a production build returns 404 here regardless of how the URL is reached.
          </p>
        </header>

        <section className="dev-error-gallery__routes mb-12">
          <h2 className="text-foreground text-lg font-semibold tracking-tight sm:text-xl">
            Navigable error routes
          </h2>
          <p className="text-muted-foreground mt-1 text-sm">
            Click to open. Use Cmd/Ctrl + click to open in a new tab.
          </p>
          <ul className="mt-4 grid grid-cols-1 gap-3 sm:grid-cols-2 lg:grid-cols-3">
            {NAVIGABLE_ROUTES.map((route) => (
              <li key={route.url}>
                <Link
                  href={route.url}
                  className={cn(
                    buttonVariants({ variant: "outline", size: "lg" }),
                    "h-auto w-full flex-col items-start gap-1 px-4 py-3 text-left whitespace-normal",
                  )}
                  data-testid={`dev-error-gallery__route-${route.url}`}
                >
                  <span className="text-foreground text-sm font-semibold">{route.label}</span>
                  <span className="text-muted-foreground text-xs leading-snug font-normal">
                    {route.description}
                  </span>
                  <span className="text-muted-foreground/80 mt-1 font-mono text-[11px]">
                    {route.url}
                  </span>
                </Link>
              </li>
            ))}
          </ul>
        </section>

        <section className="dev-error-gallery__problems">
          <h2 className="text-foreground text-lg font-semibold tracking-tight sm:text-xl">
            Inline <code className="font-mono text-base">&lt;ProblemDisplay&gt;</code> fixtures
          </h2>
          <p className="text-muted-foreground mt-1 text-sm">
            Each card renders the same component used by{" "}
            <code className="font-mono text-xs">/backoffice/banks</code> and friends. In production
            the <code className="font-mono text-xs">debug</code> block is redacted client-side
            regardless of what the API sends.
          </p>
          <div className="mt-4 space-y-6">
            {PROBLEM_FIXTURES.map((fixture) => (
              <article
                key={fixture.problem.type + fixture.problem.status}
                className="dev-error-gallery__fixture"
              >
                <header className="mb-2">
                  <h3 className="text-foreground text-sm font-semibold sm:text-base">
                    {fixture.label}
                  </h3>
                  <p className="text-muted-foreground text-xs sm:text-sm">{fixture.description}</p>
                </header>
                <ProblemDisplay problem={fixture.problem} variant="panel" />
              </article>
            ))}
          </div>
        </section>

        <footer className="dev-error-gallery__footer text-muted-foreground mt-12 border-t pt-6 text-xs">
          Need to return to a real surface?{" "}
          <Link href={Routes.HOME} className="text-primary underline-offset-4 hover:underline">
            Home
          </Link>{" "}
          ·{" "}
          <Link
            href={Routes.BACKOFFICE}
            className="text-primary underline-offset-4 hover:underline"
          >
            BackOffice
          </Link>
        </footer>
      </div>
    </div>
  );
}
