<?php

declare(strict_types=1);

namespace Erpify\Documents\Document\Infrastructure\Http;

/**
 * The two ingest route names. The content route's public path is the router's, and four patterns outside it
 * recognise that path on their own — the two Caddyfile matchers lifting its body bound, the API's whole-query
 * redaction and its PWA mirror — so each is held to the path the router serves by
 * `UploadRoutePathAgreementGateTest`, which reads it from `api/.route-manifest.json`.
 */
final class UploadRoutes
{
    public const string DECLARE = 'documents_upload_declare';

    public const string CONTENT = 'documents_upload_content';

    private function __construct()
    {
    }
}
