"use client";

import { useEffect, useRef, useState } from "react";
import { Check, Copy } from "lucide-react";
import { Button } from "@/components/ui/button";
import { cn } from "@/lib/utils";

interface CopyBankIdButtonProps {
  id: string;
  className?: string;
}

const FEEDBACK_TIMEOUT_MS = 2000;

export function CopyBankIdButton({ id, className }: CopyBankIdButtonProps) {
  const [status, setStatus] = useState<"idle" | "copied" | "error">("idle");
  const timeoutRef = useRef<ReturnType<typeof setTimeout> | null>(null);

  useEffect(() => {
    return () => {
      if (timeoutRef.current !== null) {
        clearTimeout(timeoutRef.current);
      }
    };
  }, []);

  async function handleClick(): Promise<void> {
    try {
      if (navigator.clipboard?.writeText) {
        await navigator.clipboard.writeText(id);
      } else {
        // Fallback for environments without the async clipboard API.
        const ta = document.createElement("textarea");
        ta.value = id;
        ta.setAttribute("readonly", "");
        ta.style.position = "absolute";
        ta.style.left = "-9999px";
        document.body.appendChild(ta);
        ta.select();
        document.execCommand("copy");
        document.body.removeChild(ta);
      }
      setStatus("copied");
    } catch {
      setStatus("error");
    } finally {
      if (timeoutRef.current !== null) clearTimeout(timeoutRef.current);
      timeoutRef.current = setTimeout(() => setStatus("idle"), FEEDBACK_TIMEOUT_MS);
    }
  }

  const label =
    status === "copied" ? "Copied" : status === "error" ? "Copy failed" : "Copy bank ID";

  return (
    <Button
      type="button"
      variant="outline"
      size="sm"
      onClick={handleClick}
      data-icon="inline-start"
      aria-label={label}
      title={label}
      data-testid="banks-detail__copy-id"
      data-copy-status={status}
      className={cn("banks-detail__copy-id", className)}
    >
      {status === "copied" ? (
        <Check className="size-3.5" aria-hidden="true" />
      ) : (
        <Copy className="size-3.5" aria-hidden="true" />
      )}
      <span aria-live="polite">{label}</span>
    </Button>
  );
}
