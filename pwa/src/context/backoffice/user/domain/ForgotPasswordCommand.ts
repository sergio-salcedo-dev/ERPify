/**
 * The single field submitted to request a password reset: the email the user
 * types. The address is never stored client-side; it rides only in the POST body.
 */
export interface ForgotPasswordCommand {
  email: string;
}
