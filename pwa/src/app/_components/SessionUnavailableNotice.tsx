"use client";

import { useState } from "react";
import { RefreshCw } from "lucide-react";
import { Button } from "@/components/ui/button";
import { cn } from "@/components/cn";
import { useSession } from "@/context/shared/access/application/useSession";

interface SessionUnavailableNoticeProps {
  testId: string;
  retryTestId: string;
  className?: string;
}

/**
 * Stands in for the navbar's sign-in and back-office entries while the server cannot tell
 * whether anyone is signed in. The retry asks the session provider again on this route: the
 * `unavailable` verdict is bound to the route it was seen on, so a visitor who stays on the
 * landing would otherwise never see it lift without navigating away.
 */
export function SessionUnavailableNotice({
  testId,
  retryTestId,
  className,
}: Readonly<SessionUnavailableNoticeProps>) {
  const { refresh } = useSession();
  const [retrying, setRetrying] = useState(false);

  async function handleRetry(): Promise<void> {
    setRetrying(true);
    try {
      await refresh();
    } finally {
      setRetrying(false);
    }
  }

  return (
    <div
      role="status"
      className={cn("navbar__session-unavailable flex items-center gap-2", className)}
      data-testid={testId}
    >
      <span className="navbar__session-unavailable-text text-muted-foreground text-sm font-medium">
        Sign-in temporarily unavailable
      </span>
      <Button
        type="button"
        variant="outline"
        size="sm"
        disabled={retrying}
        onClick={() => {
          void handleRetry();
        }}
        title="Try again: ask whether sign-in is available"
        aria-label="Try again"
        className="navbar__session-unavailable-retry rounded-full"
        data-testid={retryTestId}
      >
        <RefreshCw className={cn("size-4", retrying && "animate-spin")} aria-hidden="true" />
        Try again
      </Button>
    </div>
  );
}
