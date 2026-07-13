/**
 * The pair submitted to accept an invitation: the opaque invitation token read
 * verbatim from the link and the new plaintext password. The token is never
 * parsed, split, stored, or rendered; the password is never stored.
 */
export interface AcceptInvitationCommand {
  token: string;
  password: string;
}
