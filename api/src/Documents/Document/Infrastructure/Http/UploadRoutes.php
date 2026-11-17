<?php

declare(strict_types=1);

namespace Erpify\Documents\Document\Infrastructure\Http;

/**
 * The two ingest route names, and the operator's re-scan and erasure. The content route's public path is the router's,
 * and four patterns outside it recognise that path on their own — the two Caddyfile matchers lifting its body bound,
 * the API's whole-query redaction and its PWA mirror — so each is held to the path the router serves by
 * `UploadRoutePathAgreementGateTest`, which reads it from `api/.route-manifest.json`.
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

    private function __construct()
    {
    }
}
