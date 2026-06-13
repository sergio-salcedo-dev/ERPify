// Page-size options for the users list, all ≤ WIRE_MAX_LIMIT (100): the API
// caps `limit` at 100. Mirrors the Bank list options.
export const USERS_PAGE_SIZE_OPTIONS = [25, 50, 100] as const;
export type UsersPageSize = (typeof USERS_PAGE_SIZE_OPTIONS)[number];
export const USERS_PAGE_SIZE_DEFAULT: UsersPageSize = 25;
