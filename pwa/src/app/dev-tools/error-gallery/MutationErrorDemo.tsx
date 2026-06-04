"use client";

import { useState } from "react";
import { MutationError } from "@/components/erpify";
import { Button } from "@/components/ui/button";
import type { ProblemDetails } from "@/context/shared/domain/ProblemDetails";

/**
 * Client island for the gallery: `<MutationError>` needs a dismiss handler,
 * and dismissing it here should be reversible so the fixture stays browsable.
 */
export function MutationErrorDemo({ problem }: Readonly<{ problem: ProblemDetails }>) {
  const [dismissed, setDismissed] = useState(false);

  if (dismissed) {
    return (
      <Button
        type="button"
        variant="outline"
        size="sm"
        onClick={() => setDismissed(false)}
        aria-label="Restore example"
        title="Restore the dismissed example"
        data-testid="error-gallery__mutation-error-restore"
      >
        Restore example
      </Button>
    );
  }

  return (
    <MutationError
      problem={problem}
      onDismiss={() => setDismissed(true)}
      focusOnMount={false}
      testId="error-gallery__mutation-error"
    />
  );
}
