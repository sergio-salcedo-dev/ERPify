<?php

declare(strict_types=1);

namespace Erpify\Backoffice\Bank\Domain;

/**
 * Stable Mercure topic IRIs for back-office bank real-time updates (not tied to
 * hostname). The collection topic carries list-level changes (created/updated/
 * deleted); the per-bank topic carries a single bank's detail changes.
 */
final class MercureBankTopic
{
    /** List-level changes: created, updated, deleted. */
    public const string COLLECTION = 'urn:erpify:backoffice:banks';

    /** URI-template (RFC 6570) covering every per-bank topic — used for subscriber authorization. */
    public const string DETAIL_TEMPLATE = 'urn:erpify:backoffice:bank:{id}';

    /** Concrete per-bank topic for a single aggregate. */
    public static function forBank(string $bankId): string
    {
        return \sprintf('urn:erpify:backoffice:bank:%s', $bankId);
    }
}
