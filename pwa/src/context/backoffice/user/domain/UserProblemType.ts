export const UserProblemType = {
  NOT_FOUND: "user-not-found",
  // Client-side guard: the identity aggregate is invitation-based, so the read
  // adapter has no create/update/delete endpoint to call. Its write use cases
  // (invite / change-status / erase) arrive in later stories.
  NOT_SUPPORTED: "user-operation-not-supported",
} as const;
export type UserProblemType = (typeof UserProblemType)[keyof typeof UserProblemType];
