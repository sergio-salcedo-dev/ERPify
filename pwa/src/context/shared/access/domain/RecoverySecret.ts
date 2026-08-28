/**
 * What an account may know about its recovery secret without ever holding it: whether one
 * exists, and the two instants that bound its life. The secret itself is deliberately absent
 * from this shape — the API hands the plaintext over exactly once, at mint, and can never
 * produce it again, so a read that returned it would be describing something impossible.
 */
export type RecoverySecretStatus =
  | { exists: false; mintedAt: null; expiresAt: null }
  | {
      exists: true;
      /** ISO-8601 instant the secret was minted. */
      mintedAt: string;
      /**
       * ISO-8601 instant the secret stops being redeemable.
       *
       * Shown to the holder rather than kept as an implementation detail: the expiry is the
       * one way this recovery channel dies with nobody acting, so an owner who was never told
       * the date cannot plan around it. Modelled as a union rather than two nullable fields
       * for that same reason — "a secret exists but its expiry is unknown" is a state the
       * surface would have to write fallback copy for, and it is not a state the API can
       * produce. The response guard refuses the body instead.
       */
      expiresAt: string;
    };

/**
 * The single moment the plaintext exists on this client. It lives in component state for the
 * length of the confirmation view and is never written to storage, a URL, a link, or a log —
 * there is no second chance to display it, which is what the confirmation view must say.
 */
export interface MintedRecoverySecret {
  secret: string;
  mintedAt: string;
  expiresAt: string;
}
