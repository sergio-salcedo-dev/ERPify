/**
 * Identity-shaped write port for the GDPR "right to erasure", the destructive sibling of
 * {@link ChangeUserStatusRepository}: the console erases through a dedicated use case, never the generic
 * `CrudRepository.delete()` — identity administration is not CRUD. `erase` resolves to `void`: the identity is
 * hard-deleted (a 204 with no body), so there is no transitioned resource to hand back — the caller navigates
 * away from the now-absent detail instead.
 *
 * The CALLER's `currentPassword` travels with the request: an erasure is irreversible, so a borrowed session
 * must not be enough to carry one out. A wrong password is a typed `HttpError` the caller maps onto its field.
 */
export interface EraseIdentityRepository {
  erase(id: string, currentPassword: string): Promise<void>;
}
