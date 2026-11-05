<?php

declare(strict_types=1);

namespace Erpify\Shared\Audit\Infrastructure\Persistence;

use Doctrine\DBAL\Connection;
use Erpify\Shared\Audit\Application\SubjectErasureReconciler;
use Erpify\Shared\Audit\Domain\AuditErasureEvidence;
use Erpify\Shared\Crypto\Domain\DestroyReason;
use Erpify\Shared\Crypto\Domain\EncryptionScopeId;
use Override;
use Symfony\Component\DependencyInjection\Attribute\AsAlias;

/**
 * {@see SubjectErasureReconciler} via plain DBAL. A single `NOT EXISTS` join between the keystore's
 * destroyed keys and the `GDPR_SUBJECT_ERASED` audit entries: a destroyed key whose scope has no matching
 * entry is an unreconciled erasure. The reverse (evidence without a destroyed key) is expected — a subject
 * with no personal data ever keyed still records its erasure — so only this direction is a divergence.
 *
 * Two kinds of tombstone are outside this reconciliation by construction. An `aborted-upload` one closes a
 * scope whose write never completed: no erasure happened, so no entry exists to match. A `Document` scope's
 * erasure is recorded in the document's own custody, not as a `GDPR_SUBJECT_ERASED` audit entry, and is
 * reconciled against the document rows there; read here, every erased document would diverge for ever.
 */
#[AsAlias(SubjectErasureReconciler::class)]
final readonly class DbalSubjectErasureReconciler implements SubjectErasureReconciler
{
    private const string ERASURE_ACTION = AuditErasureEvidence::SUBJECT_ERASED;

    private const string DOCUMENT_SCOPE_PREFIX = EncryptionScopeId::DOCUMENT_TYPE . ':';

    public function __construct(
        private Connection $connection,
    ) {
    }

    #[Override]
    public function unreconciledScopes(): array
    {
        $scopes = $this->connection->fetchFirstColumn(
            'SELECT k.encryption_scope_id FROM dek_keystore k '
            . 'WHERE k.destroyed_at IS NOT NULL '
            . 'AND k.destroy_reason IS DISTINCT FROM :abortedUpload '
            . 'AND NOT starts_with(k.encryption_scope_id, :documentScopePrefix) '
            . 'AND NOT EXISTS ('
            . 'SELECT 1 FROM audit_log a '
            . "WHERE a.action = :action AND a.metadata->>'encryption_scope_id' = k.encryption_scope_id) "
            . 'ORDER BY k.encryption_scope_id',
            [
                'action' => self::ERASURE_ACTION,
                'abortedUpload' => DestroyReason::AbortedUpload->value,
                'documentScopePrefix' => self::DOCUMENT_SCOPE_PREFIX,
            ],
        );

        return \array_values(\array_filter($scopes, \is_string(...)));
    }
}
