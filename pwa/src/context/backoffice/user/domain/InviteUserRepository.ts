import type { Role } from "@/context/shared/access/domain/Role";

/**
 * The invitation alta input: an email plus at least one org-scoped role. An INVITED identity has no password
 * and no status yet — the invitee sets the password on accept, and the status is INVITED by construction — so
 * neither is part of the input.
 */
export interface InviteUserInput {
  email: string;
  roles: Role[];
}

/**
 * Identity-shaped write port: the console invites through a dedicated use case, never the generic
 * `CrudRepository.create()` — identity administration is not CRUD. A 201 with an empty body is success; there is
 * no resource to return (the accept token is a secret, the identity stays server-side), so `invite` resolves to
 * `void` and the console refreshes the register to show the new INVITED row.
 */
export interface InviteUserRepository {
  invite(input: InviteUserInput): Promise<void>;
}
