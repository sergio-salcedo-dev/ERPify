/**
 * Identity-shaped write port for the GDPR "right to erasure", the destructive sibling of
 * {@link ChangeUserStatusRepository}: the console erases through a dedicated use case, never the generic
 * `CrudRepository.delete()` — identity administration is not CRUD. `erase` resolves to `void`: the identity is
 * hard-deleted (a 204 with no body), so there is no transitioned resource to hand back — the caller navigates
 * away from the now-absent detail instead.
 */
export interface EraseIdentityRepository {
  erase(id: string): Promise<void>;
}
