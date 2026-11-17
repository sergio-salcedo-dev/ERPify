# Runbook — Documents erasure ledger

The Documents erasure writes the scope id of what it erases to a **ledger off the database's failure and restore domain**
before it destroys the key (`docs/adr/documents-custody-log.md` D11), so a restore to an older dump cannot bring a destroyed
key back unnoticed. This runbook holds the operator's side: setting the ledger up, the credentials, what each failure
means, the override, and the restore drill whose proof record unlocks reads.

## What it is

A restic repository behind `rest-server --append-only` on a **second host**, kept apart from the backup repository. One
entry is one snapshot tagged `erased-scope:<scope id>`. The application holds the write credential and the repository
password on the VPS (the erasure writes the entry; the completion sweep lists the ledger from there), so a VPS compromise
yields both. The `restic` binary ships in the php image, pinned by digest (`api/Dockerfile`).

Measured by the spike behind D11 (rest-server and restic 0.18.1, local):

| Question | Result |
|---|---|
| Can the write credential delete? | No. `restic forget` is answered `403` by the repository for every snapshot — **and restic still exits 0**, so the proof is the refusal text and the snapshot still being listed, never the exit code. |
| Is a read-only credential possible? | Not with a stock `rest-server`: `--append-only` is server-wide and `--private-repos` isolates users, it does not split read from write. The sweep and the restore therefore use the same credential. |
| Does a corrupt entry fail `restic check --read-data`? | Yes, exit 1. |
| Does it fail `restic snapshots`? | **No.** It prints `Ignoring "<id>": failed to load snapshot …` on stderr, skips the entry and exits 0 (with the local cache disabled; with it, the listing is served from the cache). "Read in full" is therefore `check --read-data` exit 0 **and** a listing with no `Ignoring` line, both with `--no-cache`. |
| Can the write credential remove the repository key? | No: `restic key remove` is refused. |

## Set it up

1. On the ledger host run `rest-server --append-only --path <dir> --htpasswd-file <file>` behind TLS, with one user.
2. From a workstation, once: `RESTIC_REST_USERNAME=… RESTIC_REST_PASSWORD=… RESTIC_PASSWORD=… restic init -r rest:https://<host>/<repo>`.
3. Put the four values in `.env.prod.local` (`DOCUMENTS_ERASURE_LEDGER_REPOSITORY`, `…_REST_USERNAME`, `…_REST_PASSWORD`,
   `…_PASSWORD`; the repository address carries **no** credential). Keep an **independent copy of all four off the VPS**, in
   the secrets store of `docs/vps-deployment.md`.
4. Prove it: `docker compose … exec php php bin/console documents:erasure:check-ledger` exits 0. Then prove the
   append-only property the way Gate 2 does, with the production credential against the real backend: a `restic forget` of
   a test snapshot is refused (`403`) and the snapshot is still there. Do not read the exit code: restic exits 0 on that
   refusal. Count the snapshots (`restic snapshots --json | jq length`) before and after, and require both the `403` text
   and an unchanged count.

## When the erase route answers 503

- `erasure-ledger-unavailable` — the entry is certainly not durable, nothing was erased, send the request again. It is what
  a restic that exits at once answers: no repository or password configured, a refused credential (`403`), a missing
  binary. The `observability` log line of the write carries the slug (`not-configured`, `forbidden`, `unauthorized`,
  `binary-missing`, `exit-<n>`, `timeout`), never restic's output.
- `erasure-outcome-unknown` — the write timed out (10 s), or the erasure transaction failed after the entry was durable (a lock or statement timeout, a keystore or database fault; the log line carries the cause slug). **An unreachable ledger host lands here, not in the first case**: restic retries a refused connection with backoff instead of failing, so the 10 s deadline fires first and the outcome is honestly unknown. This call erased nothing, but an entry that landed is finished by
  the completion sweep (every 5 minutes, once the entry is older than 80 s). Look at the document before sending it again.

The completion sweep writes one `error` line per tick on the `observability` channel when it cannot read the ledger: that
line is the alarm for a ledger that is down or damaged.

## Rotation

A leaked repository password **cannot be rotated while the repository is append-only** (`restic key remove` is refused). The
procedure needs an administrator session on the ledger host: stop `rest-server`, run it without `--append-only`, `restic key
add` the new password and `restic key remove` the old one, restart it with `--append-only`, then update `.env.prod.local` and
the independent copy. REST credentials rotate in the htpasswd file with no repository change.

## The override

Only when the ledger is lost or unreadable and a restore must proceed anyway. It is set by the restore itself
(`ERASURE_LEDGER_OVERRIDE=<ledger_unreachable|ledger_corrupt|ledger_lost> STAMP=<stamp> make restore.prod`, which asks in
production for the typed phrase) and prints a random `record_ref`. **Write that reference, your identity and the date in the
off-host operator log**; the database row holds none of them. While it is set every Documents read, listing and custody
trail answers 410 `gone`, and the completion tick (every 5 minutes) writes one `error` line with the count and the age of the
oldest, so a standing 410 is never silent.

**Nothing clears an override by itself, whatever its reason.** A person runs `documents:erasure:clear-override`, which
reads the set of uncleared overrides first and then refuses (non-zero, nothing cleared) unless the ledger is readable in
full, every scope of it is finished and no row of it is left un-erased; it then clears exactly the overrides it had read
and prints their `record_ref`, so the entry in the off-host operator log can be closed. It replays nothing: the replay is
the restore's or the completion sweep's. These checks are necessary and never sufficient — for a NEW, empty repository they
hold vacuously and would reopen reads over keys nobody replayed — so for a lost ledger, decide first that no key destroyed
since the backup matters (re-erase from the off-host operator log if one does), and only then clear.

## The restore drill, and the proof record the read switch waits on

Run on a scratch stack, never the live one, with the independent copy of the credentials alone (the VPS's `.env.prod.local`
is assumed gone):

1. Take a backup, erase a seeded document through the route, then restore the backup taken **before** the erasure.
2. Expect `restore.prod` to replay the ledger: the document is `Erased`, its key tombstoned, and no read of it succeeded
   between the `pg_restore` and the replay (the writers were stopped).
3. Plant a corrupted entry (overwrite one file under `snapshots/` on the ledger host) and restore again: expect recovery
   mode — writers and the scheduler stay stopped, exit non-zero. Restore the file.
4. Exercise the override: with the ledger unreadable, `ERASURE_LEDGER_OVERRIDE=ledger_corrupt …`; expect a printed
   `record_ref`, every read/list/trail answering 410 and the standing-override `error` line on the next completion tick.
   Repair the ledger, run `documents:erasure:clear-override` and expect it to print the same `record_ref`; run it first with the
   ledger still unreadable and expect a refusal.
5. Commit the `drill` record under `docs/runbooks/documents-restore-proofs/` (format and fields in
   [`documents-restore-proof.md`](./documents-restore-proof.md)) with `ledger_replay_verified: true` — **a person does this
   after running 1-4; no test writes it.** The read switch stays closed until a valid `restore` and a valid `drill` record
   both exist for `documents.backup_mechanism`.

The adapter against a real restic and an append-only rest-server is `make php.ledger.integration` (opt-in, not in CI): the
write-and-list round trip, a refused `restic forget`, and a corrupted snapshot file making the ledger unreadable. It does
not replace the drill, which needs the second host.
