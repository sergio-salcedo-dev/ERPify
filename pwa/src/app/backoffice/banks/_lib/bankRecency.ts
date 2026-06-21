import type { DateTimeProvider } from "@/context/shared/date-time-provider/domain/DateTimeProvider";

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
