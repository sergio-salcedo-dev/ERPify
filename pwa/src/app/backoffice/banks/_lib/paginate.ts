export const BANKS_PAGE_SIZE_OPTIONS = [25, 50, 100, 500, 1000] as const;
export type BanksPageSize = (typeof BANKS_PAGE_SIZE_OPTIONS)[number];
export const BANKS_PAGE_SIZE_DEFAULT: BanksPageSize = 25;
