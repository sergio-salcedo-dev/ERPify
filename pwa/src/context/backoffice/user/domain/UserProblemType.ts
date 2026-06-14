export const UserProblemType = {
  NOT_FOUND: "user-not-found",
  EMAIL_CONFLICT: "user-email-conflict",
} as const;
export type UserProblemType = (typeof UserProblemType)[keyof typeof UserProblemType];
