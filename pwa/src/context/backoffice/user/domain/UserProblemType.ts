export const UserProblemType = {
  NOT_FOUND: "user-not-found",
  // Client-side guard: the identity aggregate is invitation-based, so the read
  // adapter has no create/update/delete endpoint to call. Its write use cases
  // (invite / change-status / erase) arrive in later stories.
  NOT_SUPPORTED: "user-operation-not-supported",
  // The caller's own password, presented as proof for an authority-changing write, did not match. A 403 that
  // is a field error on the password input and never a denial of the action.
  INVALID_CURRENT_PASSWORD: "invalid-current-password",
} as const;
export type UserProblemType = (typeof UserProblemType)[keyof typeof UserProblemType];
