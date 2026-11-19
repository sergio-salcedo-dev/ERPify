<?php

declare(strict_types=1);

namespace Erpify\Documents\Document\Infrastructure\Http;

/**
 * The ingest route names, the versioning routes, and the operator's re-scan and erasures. The content route's public
 * path is the router's, and four patterns outside it recognise that path on their own — the two Caddyfile matchers
 * lifting its body bound, the API's whole-query redaction and its PWA mirror — so each is held to the path the router
 * serves by `UploadRoutePathAgreementGateTest`, which reads it from `api/.route-manifest.json`.
 */
final class UploadRoutes
{
    public const string DECLARE = 'documents_upload_declare';

    public const string CONTENT = 'documents_upload_content';

    /** The operator's re-scan of a document whose scan failed; not an ingest route, kept here for the one file. */
    public const string RESCAN = 'documents_rescan';

    /** The operator's erasure of a document by key destruction. Never `shared_`-prefixed, which opts out of audit. */
    public const string ERASE = 'documents_erase';

    /** The audited read of a document's original. Never `shared_`-prefixed: that prefix opts a route out of audit. */
    public const string READ = 'documents_read';

    /** The listing of documents' metadata. Ordinary read: the generic access log writes its activity row. */
    public const string LIST = 'documents_list';

    /** One document's metadata and its ordered custody trail. Declares `_audit_resource_type` so the row names it. */
    public const string CUSTODY = 'documents_custody';

    /** The declare of a new version of a document: step 1 of the same upload, naming its predecessor in the path. */
    public const string VERSION_DECLARE = 'documents_version_declare';

    /** One page of a version series, tombstones included. Ordinary read: the generic access log writes its row. */
    public const string SERIES = 'documents_series';

    /** The operator's erasure of every version of a series, one version per transaction, newest first. */
    public const string SERIES_ERASE = 'documents_series_erase';

    private function __construct()
    {
    }
}
