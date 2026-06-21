import { cn } from "@/context/shared/styling/infrastructure/classNames";
import { initials } from "./initials";

interface MonogramAvatarProps {
  /** Display name the initials are derived from. */
  name: string;
  /** Extra classes; defaults to a 2.25rem square tile. */
  className?: string;
  /** Optional test id passthrough (never hardcode in shared components). */
  testId?: string;
}

/**
 * Decorative monogram avatar: up-to-two initials in a brand-tinted square
 * tile. Always `aria-hidden` — the resource name is always rendered beside
 * it, so the avatar must not add a second accessible name.
 *
 * The brand-indigo tint here is an **identity affordance**, explicitly
 * sanctioned in DESIGN.md ("Color — brand and accent" governance note) as a
 * documented exception to "indigo is interactive-only". It is not a status
 * signal and never the sole carrier of meaning.
 */
export function MonogramAvatar({ name, className, testId }: Readonly<MonogramAvatarProps>) {
  const text = initials(name) || "–";
  return (
    <span
      aria-hidden="true"
      data-testid={testId}
      className={cn(
        "bg-primary/10 text-primary grid size-9 flex-none place-items-center rounded-lg text-xs font-semibold",
        className,
      )}
    >
      {text}
    </span>
  );
}
