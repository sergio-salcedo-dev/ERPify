import type { DateTimeProvider } from "@/context/shared/domain/DateTimeProvider/DateTimeProvider";

/** Default "recently created" window, in days, for the bank "New" badge. */
export const BANK_NEW_WINDOW_DAYS = 7;

/**
 * Whether `createdAtIso` falls within the last `withinDays` days relative to
 * the provider's "now". A future timestamp (clock skew) is never "new", and
 * an unparseable timestamp is treated as not-new rather than throwing.
 */
export function isRecentlyCreated(
  createdAtIso: string,
  provider: DateTimeProvider,
  withinDays: number = BANK_NEW_WINDOW_DAYS,
): boolean {
  const created = provider.parseISO(createdAtIso);
  if (!created) return false;
  const ageDays = provider.calculateDuration(created, provider.now(), "days");
  return ageDays >= 0 && ageDays <= withinDays;
}

/** Count how many of the given ISO timestamps are recently created. */
export function countRecentlyCreated(
  createdAtIsos: readonly string[],
  provider: DateTimeProvider,
  withinDays: number = BANK_NEW_WINDOW_DAYS,
): number {
  return createdAtIsos.filter((iso) => isRecentlyCreated(iso, provider, withinDays)).length;
}
