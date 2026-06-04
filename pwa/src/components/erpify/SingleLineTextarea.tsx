"use client";

import { useLayoutEffect, useRef, type ChangeEvent, type KeyboardEvent, type Ref } from "react";
import { cn } from "@/lib/utils";
import { KeyboardKey } from "@/context/shared/domain/types/keyboard";

interface SingleLineTextareaProps extends Omit<React.ComponentProps<"textarea">, "rows"> {
  ref?: Ref<HTMLTextAreaElement>;
}

// Collapses each whitespace run containing a newline to a single space. The
// single-quantifier match keeps the regex linear — no backtracking ambiguity.
function collapseNewlines(value: string): string {
  return value.replace(/\s+/g, (run) => (run.includes("\n") ? " " : run));
}

/**
 * Single-line-semantics text field that grows with its content: one visual
 * line that wraps to as many as the value needs, so the user can always read
 * everything they typed (a fixed-height input hides long values off to the
 * left). The DOMAIN value stays single-line: Enter submits the enclosing
 * form instead of inserting a break, and pasted newlines collapse to a
 * space — visibly, never silently truncating (no `maxLength`; limits live in
 * the Zod schema).
 */
export function SingleLineTextarea({
  className,
  onChange,
  onKeyDown,
  ref,
  ...props
}: Readonly<SingleLineTextareaProps>) {
  const innerRef = useRef<HTMLTextAreaElement | null>(null);

  const setRefs = (el: HTMLTextAreaElement | null): void => {
    innerRef.current = el;
    if (typeof ref === "function") {
      ref(el);
    } else if (ref) {
      ref.current = el;
    }
  };

  const resize = (el: HTMLTextAreaElement): void => {
    el.style.height = "auto";
    el.style.height = `${el.scrollHeight}px`;
  };

  // Prefilled values (edit mode) need their height on first paint.
  useLayoutEffect(() => {
    if (innerRef.current) resize(innerRef.current);
  }, []);

  const handleChange = (event: ChangeEvent<HTMLTextAreaElement>): void => {
    const el = event.currentTarget;
    if (el.value.includes("\n")) {
      el.value = collapseNewlines(el.value);
    }
    resize(el);
    onChange?.(event);
  };

  const handleKeyDown = (event: KeyboardEvent<HTMLTextAreaElement>): void => {
    if (event.key === KeyboardKey.ENTER) {
      event.preventDefault();
      event.currentTarget.form?.requestSubmit();
      return;
    }
    onKeyDown?.(event);
  };

  return (
    <textarea
      {...props}
      ref={setRefs}
      rows={1}
      data-slot="single-line-textarea"
      onChange={handleChange}
      onKeyDown={handleKeyDown}
      className={cn(
        // Mirrors components/ui/input.tsx so the field reads as an input.
        "border-input placeholder:text-muted-foreground focus-visible:border-ring focus-visible:ring-ring/50 aria-invalid:border-destructive aria-invalid:ring-destructive/20 dark:bg-input/30 dark:disabled:bg-input/80 dark:aria-invalid:border-destructive/50 dark:aria-invalid:ring-destructive/40 disabled:bg-input/50 min-h-8 w-full min-w-0 resize-none overflow-hidden rounded-lg border bg-transparent px-2.5 py-1 text-base transition-colors outline-none focus-visible:ring-3 aria-invalid:ring-3 disabled:pointer-events-none disabled:cursor-not-allowed disabled:opacity-50 md:text-sm",
        className,
      )}
    />
  );
}
