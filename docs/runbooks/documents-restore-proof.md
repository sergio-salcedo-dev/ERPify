# Runbook — Documents restore proof

Evidence lives on the `document_storage` volume and a database dump does not back it up. Before production holds
real documents, the stored bytes need an encrypted offsite backup, digest verification and a **proven restore of
originals and metadata**. This runbook fixes where the proof is recorded and in what format; the backup mechanism
itself is the operator's.

## The switch it unlocks

`documents.ingest_enabled` (`api/config/packages/documents.yaml`) opens the declare and PUT routes; while it is
false both answer 404. It is false in production. `DocumentsIngestProofGateTest` (`make php.lint.documents-ingest`)
fails when the tracked production configuration sets it true without a **valid** record of kind `restore` whose
`mechanism` equals `documents.backup_mechanism` in that same file. A record for another mechanism does not count,
and a value injected at deploy time is outside the gate — keep the switch a literal in the tracked file.

## The record

One YAML file per proof under `docs/runbooks/documents-restore-proofs/`, named `<YYYY-MM-DD>-<kind>-<mechanism>.yaml`,
committed by whoever ran the proof. Every field is required:

| Field | Meaning |
|---|---|
| `kind` | `restore` (originals and metadata restored from the backup) or `drill` (the restore drill with ledger replay, doc-7) |
| `proved_by` | who ran it (a role or a name the team recognises) |
| `proved_at` | ISO-8601 instant |
| `mechanism` | the backup mechanism exercised; must equal `documents.backup_mechanism` to unlock ingest |
| `backup_ref` | the snapshot or archive restored from |
| `originals_restored` | `true` — the sealed objects were restored into a fresh `document_storage` |
| `metadata_restored` | `true` — the `document`, `document_custody` and `dek_keystore` rows were restored with them |
| `digest_set_verified` | `true` — every restored document's stored digest was recomputed from the decrypted bytes and matched |
| `decryption_verified` | `true` — the restored originals decrypted under the restored keys |
| `result` | `passed`; any other value makes the record invalid |

## Procedure

1. Restore the backup named by `backup_ref` into an isolated stack (never the live one), with writers, sweeps and
   the reaper off.
2. For every `document` row not `erased`: open its final object through the storage port, unseal it with the
   restored key, recompute the sha256 and compare it with the row's `digest`.
3. Record the outcome as above and commit it on a branch with the configuration change that names the mechanism.

doc-5's read gate reads the same directory; doc-7 adds its `drill` record here.
