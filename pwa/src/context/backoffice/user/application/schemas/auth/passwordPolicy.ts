/** Shared password bounds for the auth schemas that set a new credential. */
export const PASSWORD_MIN_LENGTH = 8;
/** Upper bound: comfortably above any real password, below slow-hash DoS territory. */
export const PASSWORD_MAX_LENGTH = 128;
