# Evidence lifecycle

Logical states of a document and who may act in each. They are not table columns: they persist as the `Document` snapshot, with each transition appended to `document_custody` (see `SPEC.md`). Names are working names for the first story to confirm; the invariants are not.

```mermaid
stateDiagram-v2
    [*] --> Received: UploadEvidence (row committed with the sealed stream still in staging; renamed to final right after)
    Received --> Scanning: sweep on next_scan_at (promoted, both counters below their caps), woken by the post-commit message, claims a lease after a scanner health probe
    Scanning --> Ready: clean
    Scanning --> Quarantined: infected
    Scanning --> ScanFailed: deterministic failure (over the size limit, unscannable); a verdict, own custody row, no retries
    Scanning --> Received: no verdict (reset, timeout, crash, worker death, lease expiry, deadline; burns an attempt if streaming had begun and the re-probe finds the scanner up; otherwise counts an outage return, the connect failing before the first byte included)
    Scanning --> ScanFailed: attempt cap or outage-return cap reached on a no-verdict return (same compare-and-set as the return)
    ScanFailed --> Received: operator decision (own custody row, resets both counters; requires a non-Erased predecessor and a free version slot, else a conflict)
    Received --> Erased: destroyScope()
    Scanning --> Erased: destroyScope()
    Ready --> Erased: destroyScope()
    Quarantined --> Erased: destroyScope()
    ScanFailed --> Erased: destroyScope()
    Erased --> [*]
```

| State | Content readable | Derivatives | Notes |
|---|---|---|---|
| Received | no | none | digest computed over the original during the write; awaiting a scan |
| Scanning | no | none | holds a lease; scanning classifies, it never rewrites the byte |
| Ready | yes, via the voter (CAP-9) | may be materialised | the only state a consumer reaches |
| Quarantined | no | none | infected bytes kept sealed for custody, never served; erasable by an authorised operator |
| ScanFailed | no | none | unscannable (a deterministic scanner failure, or the attempt or outage-return cap reached); erasable, re-scannable only by an operator decision |
| Erased | no | all deleted | key destroyed and the sealed object deleted after commit; digest and custody record stay |

Invariants over the table:

- **A document is scanned only once its object is in the final namespace.** `Received` is entered when the row commits, before the rename, so the scan message follows the rename and a `Received` row whose object is still staged is rolled forward by a sweep, never scanned and never reaped. A lease return (`Scanning` back to `Received`) and a re-claim write no custody row; the first claim since the last operator decision, every verdict (`ScanFailed` by cap or by deterministic failure included) and every operator decision do. The claim's compare-and-set predicate requires `promoted_at` not `NULL` (set after the rename and directory sync, by the writer or by the roll-forward sweep), `scan_attempts` and `scan_outage_returns` below their caps and `next_scan_at <= NOW()`: a row that fails it is not claimed, no epoch is taken and no attempt is burnt, and a claim whose connect to the scanner fails before the first byte counts an outage return. A scanner that fails its health probe is the same case: nothing is claimed, so an outage never moves a document toward `ScanFailed`. The roll-forward sweep skips `Erased` rows, whose object belongs to the delete step below, and the missing-object alarm exempts them.
- **No consumer reaches content outside `Ready`.** The gate sits on the aggregate, not in a controller.
- **Erasure is reachable from every non-terminal state and never waits on a scan.** An erasure with the scanner down must still be satisfiable.
- **`Erased` is terminal and idempotent.** `destroyScope()` returns false on a repeat and on a scope never minted, so that boolean never decides `Erased`: the aggregate's compare-and-set does, and a repeat appends no custody row. Every state change is a compare-and-set that `Erased` rejects, so a verdict that arrives after erasure is discarded.
- **Erasure orders derivative deletion after commit**, through the existing `ImageDeletionRequested` signal; a failure of the image delete retries and never rolls the erasure back. Each retry re-queries by origin, so a derivative created after the first attempt is still found, and the materialisation entry point checks origin liveness in its own transaction and deletes a late arrival. An orphan sweep catches `Image` rows whose origin is erased or absent.
- **The sealed object of an `Erased` document is deleted after commit.** The delete is idempotent and has a single owner, a sweep that removes the staged path and then the final path, tolerates ENOENT on each and marks the row done only when neither exists; an alarm fires on an object older than a bound measured from the `Erased` custody row, and a failed delete never rolls the erasure back. A read that finds the object gone re-reads the row and answers an `Erased` one as gone, without an alarm.
- **Sealing under a destroyed scope is refused.** A late writer (a retry, a re-seal) cannot bring an erased document's key back.
- **The original is never rewritten in any state.** A correction is a new version (CAP-7), not an edit.
- **A person's erasure never erases a document.** It anonymises their references; content erasure is per document, each version having its own scope, and erasing a series is a repeatable operator action over its non-`Erased` versions, one transaction each. Links to an `Erased` node stay readable as tombstones. An `Erased` node that still has a live descendant keeps its version slot, so the accepted chain never forks around it.
- **OCR (CAP-8) runs from `Ready` only**, reading the original through the same decrypt path; its text derivative has the same dependence on its origin as a preview.
- **Stuck documents are visible:** an alarm fires on a document older than a bound that is not in `Ready`, `Quarantined`, `ScanFailed` or `Erased`, and a second one on a `Quarantined` or `ScanFailed` document older than a bound. Age is measured from the last custody-bearing event (entry into `Received`, the first claim, a verdict, an operator decision), which a lease return does not touch, so a document looping through an outage still ages and an operator re-scan of an old document does not alarm at once. The second alarm exists because those two states wait for an operator and would otherwise be a silent sink.

## Reconciling the old `#268` checklist

| Old item | Where it went |
|---|---|
| `UploadedDocument` port wrapping `UploadedFile` | dropped; ADR invariant 6 (stream from the Infrastructure adapter) |
| Own storage port, stream write (old `#266`) | CAP-1, CAP-3; doc-1 (sealing) and doc-2 (port) |
| Dropbox / S3 backends (old `#267`) | decided: local filesystem first (`SPEC.md` Constraints); S3 and object-lock deferred, no story |
| Pipeline without transformation | CAP-1; doc-3 |
| Antivirus with not-ready gate | CAP-2; doc-4 |
| Stream sibling of `EnvelopeEncryptor` | CAP-3; doc-1 |
| Erasure by `destroyScope()` keeping digest and custody | CAP-4; doc-7 |
| Coordinated derivative deletion | CAP-5; doc-8 |
| Metadata | CAP-6; doc-6 |
| Versioning | CAP-7; doc-9 |
| OCR of scanned PDFs | CAP-8; doc-10 |
| (new) audited read path | CAP-9; doc-5 |
