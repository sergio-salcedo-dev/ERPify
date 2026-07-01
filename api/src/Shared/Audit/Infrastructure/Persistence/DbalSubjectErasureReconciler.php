<?php

declare(strict_types=1);

namespace Erpify\Shared\Audit\Infrastructure\Persistence;

use Doctrine\DBAL\Connection;
use Erpify\Shared\Audit\Application\SubjectErasureReconciler;
use Override;
use Symfony\Component\DependencyInjection\Attribute\AsAlias;

/**
 * {@see SubjectErasureReconciler} via plain DBAL. A single `NOT EXISTS` join between the keystore's
 * destroyed keys and the `GDPR_SUBJECT_ERASED` audit entries: a destroyed key whose scope has no matching
 * entry is an unreconciled erasure. The reverse (evidence without a destroyed key) is expected — a subject
 * with no personal data ever keyed still records its erasure — so only this direction is a divergence.
 */
#[AsAlias(SubjectErasureReconciler::class)]
final readonly class DbalSubjectErasureReconciler implements SubjectErasureReconciler
{
    private const string ERASURE_ACTION = 'GDPR_SUBJECT_ERASED';

    public function __construct(
        private Connection $connection,
    ) {
    }

    #[Override]
    public function unreconciledScopes(): array
    {
        $scopes = $this->connection->fetchFirstColumn(
            'SELECT k.encryption_scope_id FROM dek_keystore k '
            . 'WHERE k.destroyed_at IS NOT NULL AND NOT EXISTS ('
            . 'SELECT 1 FROM audit_log a '
            . "WHERE a.action = :action AND a.metadata->>'encryption_scope_id' = k.encryption_scope_id) "
            . 'ORDER BY k.encryption_scope_id',
            ['action' => self::ERASURE_ACTION],
        );

        return \array_values(\array_filter($scopes, \is_string(...)));
    }
}
