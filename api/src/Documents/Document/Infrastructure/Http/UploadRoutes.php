<?php

declare(strict_types=1);

namespace Erpify\Documents\Document\Infrastructure\Http;

/**
 * The two ingest route names and the public path of the content route. The path is spelled here once because
 * three readers outside the router need it — the Caddy body bound, the URI redaction that replaces the whole
 * query on this route, and the ingest gate — and an anchored pattern drifting from the route is a bound or a
 * redaction that silently stops matching.
 */
final class UploadRoutes
{
    public const string DECLARE = 'documents_upload_declare';

    public const string CONTENT = 'documents_upload_content';

    private function __construct()
    {
    }
}
