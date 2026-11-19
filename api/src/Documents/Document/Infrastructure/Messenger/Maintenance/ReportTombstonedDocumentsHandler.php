<?php

declare(strict_types=1);

namespace Erpify\Documents\Document\Infrastructure\Messenger\Maintenance;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Types\Types;
use Erpify\Documents\Document\Domain\DocumentState;
use Erpify\Shared\Crypto\Domain\EncryptionScopeId;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

/**
 * The Document-driven tombstone reconciler. The shared subject-erasure reconciler is driven by audit rows and skips
 * `Document` scopes by design, so nothing else notices a document whose key was destroyed while its row stayed alive:
 * its bytes can never be read again, and the document would be listed, offered and scanned as if it were sound.
 *
 * It starts from the DOCUMENT rows, not from the keystore: one `error` line per run when any row that is not `Erased`
 * has a destroyed scope, **whatever the ledger says** — the ledger may hold the scope, in which case the completion
 * sweep will finish it and this line stops by itself, or may not, which is the case that needs a person. Ids and counts
 * only, never a scope, a name or an actor. Read-only and idempotent.
 */
#[AsMessageHandler]
final readonly class ReportTombstonedDocumentsHandler
{
    private const int IDS_PER_LINE = 20;

    private const int SCAN_LIMIT = 1_000;

    public function __construct(
        private Connection $connection,
        #[Autowire(service: 'monolog.logger.observability')]
        private LoggerInterface $logger,
    ) {
    }

    public function __invoke(ReportTombstonedDocumentsMessage $message): void
    {
        unset($message);

        /** @var list<string> $ids */
        $ids = $this->connection->fetchFirstColumn(
            'SELECT d.id FROM document d JOIN dek_keystore k '
            . "ON k.encryption_scope_id = :type || ':' || d.scope_id "
            . 'WHERE k.destroyed_at IS NOT NULL AND d.state <> :erased ORDER BY d.id LIMIT :limit',
            [
                'type' => EncryptionScopeId::DOCUMENT_TYPE,
                'erased' => DocumentState::Erased->value,
                'limit' => self::SCAN_LIMIT,
            ],
            ['limit' => Types::INTEGER],
        );

        if ([] === $ids) {
            return;
        }

        $this->logger->error('Documents that are not erased have a destroyed key.', [
            'count' => \count($ids),
            'documentIds' => \array_slice($ids, 0, self::IDS_PER_LINE),
        ]);
    }
}
