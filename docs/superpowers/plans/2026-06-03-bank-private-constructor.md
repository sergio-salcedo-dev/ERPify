# Bank Private Constructor — Implementation Plan (follow-up)

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax.

**Goal:** Funnel `Bank` construction through a `private __construct()` that initializes every property (mirroring the `BankAccount` refactor in commit `78ea04d`), and drop `Bank` from the PHPStan `property.uninitialized` ignore.

**Architecture:** `create()` keeps its public signature, performs normalization, delegates to the private constructor via `new self(...)`, and **then** records `BankCreatedDomainEvent`. The constructor stays event-free so Doctrine hydration / any future reconstitution path never emits a creation event. `rename()` / `delete()` are unchanged (they mutate already-constructed aggregates).

**Tech Stack:** PHP 8.5 · Symfony 8 · Doctrine ORM · PHPUnit · PHPStan (max) · Psalm · Rector.

**Precedent:** `BankAccount` got the identical treatment in `78ea04d` (this branch). Replicate the shape; the only new wrinkle is the domain-event recording in `create()`.

---

## Commit policy (ERPify)

Commit only when the user asks; Conventional Commits; stage files explicitly (no `git add -A`); verify branch first; never amend the user's commits; never stage `api/config/reference.php` (auto-generated). No git hooks are installed in this checkout, so commit normally (no `--no-verify`).

## Why this is a separate change from `BankAccount`

`Bank` is a larger aggregate (Media relation, normalized-name column, three domain events, `rename`/`delete` mutators). The risky part is **not** moving construction — it's making sure the `BankCreatedDomainEvent` keeps being recorded exactly once, on the create path only. Keeping that in `create()` (post-construction) preserves today's behavior precisely.

## Pre-flight (do before editing)

- [ ] **Confirm no external instantiation** that a private constructor would break:

Run: `grep -rn "new Bank(" api/src api/tests api/features`
Expected: no matches (fixtures use `__factory: Bank::create`; `create()` uses `new self()`). If any external `new Bank(...)` exists, stop and report — it must move to `Bank::create()` first.

---

## Task 1: Introduce the private constructor and delegate from `create()`

**Files:**
- Modify: `api/src/Backoffice/Bank/Domain/Entity/Bank.php`

- [ ] **Step 1: Add the private constructor and rewrite `create()` to delegate**

Replace the existing `create()` method (the `public static function create(...) { ... return $bank; }` block) with the constructor below **followed by** the delegating `create()`. Leave the property declarations (lines ~28–65), `rename()`, `delete()`, and all getters untouched.

```php
    private function __construct(
        string $id,
        string $name,
        string $nameNormalized,
        string $shortName,
        ?Media $media,
        ?string $storedObjectKey,
        ?string $storedObjectMimeType,
        ?int $storedObjectByteSize,
        ?string $storedObjectContentHash,
    ) {
        parent::__construct();

        $this->id = $id;
        $this->name = $name;
        $this->nameNormalized = $nameNormalized;
        $this->shortName = $shortName;
        $this->media = $media;
        $this->storedObjectKey = $storedObjectKey;
        $this->storedObjectMimeType = $storedObjectMimeType;
        $this->storedObjectByteSize = $storedObjectByteSize;
        $this->storedObjectContentHash = $storedObjectContentHash;
    }

    public static function create(
        string $id,
        string $createEventId,
        string $name,
        string $shortName,
        ?Media $media = null,
        ?StoredObject $storedObject = null,
    ): self {
        $normalizedText = NormalizedText::from($name);

        $bank = new self(
            $id,
            $normalizedText->display,
            $normalizedText->normalized,
            NormalizedText::toAsciiUpper($shortName),
            $media,
            $storedObject?->objectKey,
            $storedObject?->mimeType,
            $storedObject?->byteSize,
            $storedObject?->contentHash,
        );

        $createdAt = $bank->createdAt->format(DateTimeInterface::ATOM);

        $bank->record(new BankCreatedDomainEvent(
            $id,
            $createEventId,
            $bank->name,
            $bank->shortName,
            $createdAt,
            $createdAt,
            $media?->getId(),
            $media?->getContentHash(),
            $storedObject?->contentHash,
            $storedObject?->mimeType,
        ));

        return $bank;
    }
```

Notes:
- **The event recording stays in `create()`**, after `new self(...)`. Do NOT move `record(new BankCreatedDomainEvent(...))` into the constructor — hydration/reconstitution must not emit it.
- `createEventId` is an event field, not a property; it stays a `create()` parameter and is not passed to the constructor.
- `$bank->createdAt` is set by `parent::__construct()` inside the constructor, so it is available when `create()` builds the event ATOM string.
- `storedObject` is decomposed into its four columns by `create()`; the constructor receives the already-decomposed nullable fields.

- [ ] **Step 2: Static analysis (before Rector promotes anything)**

Run: `make php.stan` then `make php.psalm`
Expected: no errors. (`Bank` is still in the `property.uninitialized` ignore at this point, so it would pass regardless — the meaningful PHPStan check happens in Task 2 after the ignore is removed.)

- [ ] **Step 3: Let Rector promote the constructor properties (repo convention) and re-verify**

Run: `make php.quality`
Expected: EXIT 0. Rector's `codeQuality` set promotes the constructor properties — it deletes the top-of-class property declarations and moves their `#[ORM\Column]` / `#[Assert\*]` / `#[Groups]` attributes onto the promoted constructor parameters (exactly as it did for `BankAccount` in `78ea04d`). This is the enforced repo convention; commit the promoted result. If the `php.md` or `php.rector` step dies with `Error 137`, that is a transient OOM — just re-run `make php.quality`.

- [ ] **Step 4: Confirm runtime behavior is unchanged (construction + event still work)**

Run: `make db.validate`
Expected: `[OK] The mapping files are correct.` and `[OK] The database schema is in sync with the mapping files.`

Run: `make db.load.fixtures`
Expected: `fixtures loaded` (the 31 `Bank.yaml` fixtures construct via `Bank::create` → the new private constructor; `BankCreatedDomainEvent` is still recorded per bank exactly as before).

Run: `make sf c="dbal:run-sql \"SELECT name, short_name FROM bank WHERE short_name = 'JPM'\""`
Expected: one row — `JPMorgan Chase | JPM` (proves normalization via `create()` still runs through the delegated constructor).

---

## Task 2: Drop `Bank` from the PHPStan `property.uninitialized` ignore

**Files:**
- Modify: `api/tools/phpstan/phpstan.neon`

- [ ] **Step 1: Remove the `Bank.php` line from the `property.uninitialized` ignore**

In `api/tools/phpstan/phpstan.neon`, the `property.uninitialized` block currently lists `Bank.php` and `Media.php` (it lost `BankAccount.php` in `78ea04d`). Remove the `Bank.php` line so only `Media.php` remains:

```neon
        # Doctrine entities: typed properties are populated by the ORM hydrator (or the static factory)
        # rather than the constructor, so PHPStan's checkUninitializedProperties can't see them.
        # BankAccount and Bank are excluded: their private constructors initialize every property.
        - identifier: property.uninitialized
          paths:
              - ../../src/Shared/Media/Domain/Entity/Media.php
```

**Do NOT touch the separate `property.onlyWritten` ignore for `Bank.php`** (further down the file) — `nameNormalized` is still written-but-never-read-via-getter, so that entry must stay.

- [ ] **Step 2: Verify the ignore removal is a real fix**

Run: `make php.stan`
Expected: `[OK] No errors` — the constructor initializes every typed property (own props via promotion, `id` assigned, `createdAt`/`updatedAt` via `parent::__construct()`), so `Bank` no longer needs the suppression. If PHPStan reports `property.uninitialized` on `Bank`, restore the line and report which property it flags.

---

## Task 3: Final verification + commit

- [ ] **Step 1: Full sweep**

Run: `make php.quality` (EXIT 0) and `make php.unit` (all green, e.g. `Tests: 499`). Re-run if a transient `Error 137` OOM appears.

- [ ] **Step 2: Confirm only the two intended files changed**

Run: `git checkout -- api/config/reference.php; git status --short`
Expected: only `api/src/Backoffice/Bank/Domain/Entity/Bank.php` and `api/tools/phpstan/phpstan.neon` modified.

- [ ] **Step 3: Commit (checkpoint — confirm per Commit policy)**

```bash
git add api/src/Backoffice/Bank/Domain/Entity/Bank.php api/tools/phpstan/phpstan.neon
git commit -m "refactor(backoffice): construct Bank via a private constructor"
```

- [ ] **Step 4: Push (only if the user asks)** to update the open PR.

---

## Guardrails / non-effects

- **Domain events unchanged.** `create()` still records exactly one `BankCreatedDomainEvent`; `rename()` and `delete()` are untouched. No change to the audit/Messenger flow.
- **Doctrine is fine with a private constructor** — it hydrates via `Instantiator::newInstanceWithoutConstructor()`, bypassing the constructor entirely.
- **Fixtures unaffected** — `Bank.yaml` uses `__factory: Bank::create`, which never touches the private constructor.
- **Rector promotion is expected and accepted** (repo-wide `codeQuality` set). The entity's column shape will live in the constructor signature, including the `shortName`/`name` `#[Groups]` attributes and the `nameNormalized` column.
- **No migration, no DB schema change** — this is a construction refactor only; column mapping is identical.
- **Scope:** `Bank` only. `Media` keeps its `property.uninitialized` ignore (it has no constructor and is out of scope).
