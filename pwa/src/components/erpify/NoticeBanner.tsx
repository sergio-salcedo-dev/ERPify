import Link from "next/link";
import { cn } from "@/components/cn";
import { safeHref } from "@/context/shared/navigation/domain/safeHref";
import { statusDotVariants, type StatusBadgeVariant } from "./StatusBadge";

interface NoticeBannerProps {
  /** Accessible name of the landmark; a banner without one is an anonymous `complementary`. */
  label: string;
  tone: StatusBadgeVariant;
  text: string;
  link: { href: string; label: string };
  className?: string;
  /** Optional test id passthrough (never hardcode in shared components). */
  testId?: string;
  /** Optional test id for the link, which is the banner's only control. */
  linkTestId?: string;
}

/**
 * A standing notice: a tone, one sentence and the link that resolves it. It is a named
 * `<aside>` landmark — reachable by landmark navigation and read in order — and deliberately
 * NOT a live region. A notice that stands on every page of a shell would, as `role="status"`,
 * `<output>` or `aria-live`, re-announce itself on every navigation and compete with the
 * regions that report something that just happened. It has no dismiss: whatever it reports is
 * resolved by following the link, not by closing it.
 *
 * The hue is the same dot {@link StatusBadge} uses, so the text keeps its neutral contrast and
 * the tone never rests on colour alone.
 */
export function NoticeBanner({
  label,
  tone,
  text,
  link,
  className,
  testId,
  linkTestId,
}: Readonly<NoticeBannerProps>) {
  return (
    <aside
      aria-label={label}
      className={cn(
        "notice-banner border-border bg-card text-foreground flex flex-wrap items-center gap-x-3 gap-y-1 border-b px-4 py-2.5 text-sm md:px-8",
        className,
      )}
      data-testid={testId}
    >
      <span className={statusDotVariants({ variant: tone })} aria-hidden="true" />
      <p className="notice-banner__text min-w-0 flex-1">{text}</p>
      <Link
        href={safeHref(link.href)}
        className="notice-banner__link text-foreground font-medium underline underline-offset-4"
        data-testid={linkTestId}
      >
        {link.label}
      </Link>
    </aside>
  );
}
