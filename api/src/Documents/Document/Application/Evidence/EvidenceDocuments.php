<?php

declare(strict_types=1);

namespace Erpify\Documents\Document\Application\Evidence;

use Erpify\Documents\Document\Domain\Exception\DocumentGone;

/**
 * The read seam Documents publishes to the contexts that attach evidence (a bank account today). A consumer holds a
 * document by id only — no object graph crosses the boundary — and learns about it through this port: the terms it
 * was filed under, its lifecycle state, and, for a page of links, its opened name.
 *
 * It is the only Documents type a consumer imports, and each importer is a line of `api/.bounded-context-allowlist`
 * mirrored in deptrac's `skip_violations`. The two read models it returns are used without being named. It reads; it
 * never files, erases or reaches the bytes of an original, which stay behind the audited read route.
 */
interface EvidenceDocuments
{
    /**
     * The filing of one document, or null when no document has that id. Opens no name and costs no keystore query.
     */
    public function filingOf(string $documentId): ?EvidenceFiling;

    /**
     * The filings of a set of documents, keyed by id; an id with no document is absent from the result.
     *
     * @param list<string> $documentIds
     *
     * @return array<string, EvidenceFiling>
     */
    public function filingsOf(array $documentIds): array;

    /**
     * What a consumer's listing shows of each document, keyed by id, with every name of the set opened in ONE keystore
     * query. An unopenable name — a destroyed key, a failed authentication, an `Erased` row — is a null on its own
     * document, never a failure of the set. An id with no document is absent from the result.
     *
     * @param list<string> $documentIds
     *
     * @throws DocumentGone while the erasure ledger's override is set: a restore may have brought back the key of a
     *                      document the ledger recorded as erased, and its name would open
     *
     * @return array<string, EvidenceDocument>
     */
    public function describe(array $documentIds): array;
}
