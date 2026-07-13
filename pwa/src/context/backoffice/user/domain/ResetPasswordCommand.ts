/**
 * The pair submitted to reset a password: the opaque reset token read verbatim
 * from the emailed link and the new plaintext password. The token is never
 * parsed, split, stored, or rendered; the password is never stored.
 */
export interface ResetPasswordCommand {
  token: string;
  password: string;
}
