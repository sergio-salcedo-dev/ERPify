"use client";

import { AsyncBoundary, MutationError, StatusBadge } from "@/components/erpify";
import { Button } from "@/components/ui/button";
import { dateTimeProvider } from "@/context/shared/date-time-provider/infrastructure";
import { useMySessions } from "../../application/useMySessions";
import type { SessionSummary } from "../../domain/SessionSummary";

/**
 * The signed-in user's active sessions. The current session is labelled "This
 * device" textually (not by colour alone); the only revoke affordance is the
 * bulk "Sign out other devices", which never closes the current session. After a
 * successful revoke the list simply refreshes — a calm signal, no toast.
 *
 * Not wired into a route/menu here; a follow-up can place it.
 */
export function MySessions() {
  const {
    state,
    sessions,
    problem,
    hasOtherSessions,
    revoking,
    revokeProblem,
    revokeOthers,
    dismissRevokeProblem,
  } = useMySessions();

  return (
    <section
      className="my-sessions space-y-4"
      data-testid="my-sessions"
      aria-labelledby="my-sessions__title"
    >
      <div className="my-sessions__header flex items-center justify-between gap-3">
        <h2
          id="my-sessions__title"
          className="my-sessions__title text-foreground text-lg font-semibold"
        >
          Active sessions
        </h2>
        <Button
          type="button"
          variant="outline"
          size="sm"
          disabled={!hasOtherSessions || revoking}
          onClick={() => {
            void revokeOthers();
          }}
          title="Sign out every other device, keeping this one signed in"
          aria-label="Sign out other devices"
          data-testid="my-sessions__revoke-others"
        >
          Sign out other devices
        </Button>
      </div>

      {revokeProblem ? (
        <MutationError
          problem={revokeProblem}
          onDismiss={dismissRevokeProblem}
          testId="my-sessions__revoke-error"
        />
      ) : null}

      <AsyncBoundary
        state={state}
        data={sessions}
        error={problem ?? undefined}
        emptyHeading="No active sessions"
        emptyDescription="There are no active sessions to show."
      >
        {(rows) => (
          <ul className="my-sessions__list space-y-2" data-testid="my-sessions__list">
            {rows.map((session) => (
              <SessionRow key={session.id} session={session} />
            ))}
          </ul>
        )}
      </AsyncBoundary>
    </section>
  );
}

function SessionRow({ session }: Readonly<{ session: SessionSummary }>) {
  return (
    <li
      className="my-sessions__row border-border flex items-center justify-between gap-3 rounded-md border p-3"
      data-testid={`my-sessions__row-${session.id}`}
    >
      <div className="my-sessions__row-info min-w-0 space-y-0.5">
        <p className="my-sessions__device text-foreground truncate text-sm font-medium">
          {session.device}
        </p>
        <p
          className="my-sessions__created text-muted-foreground text-xs"
          title={dateTimeProvider.formatIsoToLocalDateTime(session.createdAt)}
        >
          Signed in {dateTimeProvider.formatIsoToRelative(session.createdAt)}
        </p>
      </div>
      {session.current ? (
        <StatusBadge
          variant="success"
          label="This device"
          testId={`my-sessions__current-${session.id}`}
        />
      ) : null}
    </li>
  );
}
