# Sentry — filtering `domain_error` noise (deferred improvement)

> **Status:** NOT implemented. This is a design note for a deliberate future
> tuning decision. Captured so the trade-off isn't re-derived from scratch.
> Related: [`api-error-contract.md`](api-error-contract.md) (the `exception_category`
> taxonomy) and [`../_bmad-output/implementation-artifacts/deferred-work.md`](../_bmad-output/implementation-artifacts/deferred-work.md).

## The problem: Sentry is for bugs, not for expected "no"s

Sentry exists to surface **unexpected failures** (a 500, a `TypeError`, a crash).
But the API also throws **domain exceptions**, which are the server behaving
**correctly** by rejecting a bad or absent request:

- `BankNotFoundException` → 404 ("that bank doesn't exist")
- `InvalidInput` → 400 ("that payload is invalid")
- `Conflict` → 409 ("already exists")
- `Forbidden` / `Unauthenticated` → 403 / 401

These are **not bugs**. They are the API correctly saying "no".

### Why they reach Sentry today

The SentryBundle `ErrorListener` captures **every** throwable on
`kernel.exception` except those in `ignore_exceptions`, which currently only
lists framework noise:

```yaml
# api/config/packages/sentry.yaml
ignore_exceptions:
    - 'Symfony\Component\ErrorHandler\Error\FatalError'
    - 'Symfony\Component\Debug\Exception\FatalErrorException'
    - 'Symfony\Component\HttpKernel\Exception\NotFoundHttpException'  # unmatched-route 404s
```

`DomainException` subclasses are **not** excluded, so every client 400/404/409
becomes a Sentry event. (Verified live: probing endpoints with bad input
produced `domain_error` events in the dev Sentry project.)

### Why it matters

1. **Signal-to-noise** — real bugs (the 500s) get buried under routine 404s.
2. **Quota / cost** — Sentry bills by event volume; a public API emits piles of 4xx.
3. **Alert fatigue** — a "spike in errors" rule fires when a client hammers a 404.
4. **Misleading metrics** — the error rate looks terrible when 99% are normal
   business outcomes.

This is already half-acknowledged in the codebase: `exception_category` (see
[`api-error-contract.md`](api-error-contract.md)) tags these as `domain_error`,
and the routing table documents **`domain_error → log only`** (never page). The
filter simply extends that policy to Sentry: domain errors stay in the **logs**
(auditable by `correlation-id`) but don't become Sentry **issues**.

## What "sample `domain_error` to 0%" means

In Sentry, a *sample rate* is the **fraction of events kept**: `1.0` = all,
`0.0` = none.

- **`domain_error` at 0%** = drop 100% of domain errors.
- You could instead keep a small fraction (e.g. `0.02` = 2%) to retain a
  representative trickle for spotting spikes.

## Two ways to implement

### Option A — one line in `ignore_exceptions` (simplest, all-or-nothing)

```yaml
# api/config/packages/sentry.yaml
ignore_exceptions:
    - 'Symfony\Component\ErrorHandler\Error\FatalError'
    - 'Symfony\Component\Debug\Exception\FatalErrorException'
    - 'Symfony\Component\HttpKernel\Exception\NotFoundHttpException'
    - 'Erpify\Shared\Domain\Exception\DomainException'   # covers ALL markers
```

The SDK matches with `is_a($className, $pattern, true)`
(`sentry/sentry` `Client.php`), so this one base-class entry drops every
subclass — `NotFound`, `Conflict`, `InvalidInput`, … — and is applied **before**
`before_send` (efficient). Limitation: it's **binary** — all or nothing, no
sampling, no keeping the interesting ones.

### Option B — drop in `before_send` (flexible, where sampling lives)

Re-add the `?EventHint $hint` arg to
[`SentryEventScrubber`](../api/src/Shared/Infrastructure/Monitoring/SentryEventScrubber.php)
(the cs-fixer removed it as unused); `EventHint::$exception` is the original
throwable:

```php
public function __invoke(Event $event, ?EventHint $hint = null): ?Event
{
    // Expected business outcomes (404/400/409…) aren't bugs → drop.
    if ($hint?->exception instanceof DomainException) {
        return null;            // 0% kept → nothing sent
    }
    // ...existing PII scrub...
    return $event;
}
```

This unlocks what Option A can't:

- **Sampling** (keep ~2%): `if ($hint?->exception instanceof DomainException && random_int(1, 100) > 2) { return null; }`
- **Conditional**: drop domain 4xx but **keep** domain 5xx, or keep
  `Unauthenticated` / `Forbidden` (a security signal).

The base class is
[`DomainException`](../api/src/Shared/Domain/Exception/DomainException.php)
(`Erpify\Shared\Domain\Exception\DomainException`).

## The trade-off — why this is deferred, not a default

**0% can be too aggressive.** Silencing *all* domain errors also blinds you to
patterns that matter:

- A spike of **401/403** can mean credential-stuffing or enumeration (an attack).
- A spike of **409** can mean a buggy client double-submitting.
- A spike of **400** on one endpoint can mean a frontend regression sending bad
  payloads.

So the right policy isn't a blanket 0% — it usually lands on a **low sample**
(2–5%) or **keep 5xx / security exceptions**, and that decision wants real
traffic to inform it. Hence: deferred.

## Recommendation

Prefer **Option B with a low sample** (e.g. 2%) over a hard 0%, so spikes still
surface as trends — this aligns with the security checklist's interest in
401/403/409 anomalies. Use **Option A** only if maximum simplicity is wanted and
losing those signals is acceptable. Whichever is chosen, ship it with a unit
test alongside the existing `SentryEventScrubberTest`.
