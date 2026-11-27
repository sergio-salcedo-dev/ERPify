<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * The audit timeline's `contains` filter on `action` is a `LIKE '%…%'`, which a B-tree cannot serve: measured over
 * 1.5M rows it read the whole table (95 ms, and the same for a typo that matches nothing), and 0.1 ms through the GIN
 * trigram index below. The index is 32 MB there and costs about 8 microseconds per audit write.
 *
 * `down()` drops the index and leaves `pg_trgm` installed: another index (`bank_account`'s) may depend on it.
 */
final class Version20261127060000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Audit timeline: a pg_trgm GIN index so the contains filter on action stops scanning the table.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE EXTENSION IF NOT EXISTS pg_trgm');
        $this->addSql('CREATE INDEX IF NOT EXISTS idx_audit_log_action_trgm ON audit_log USING gin (action gin_trgm_ops)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP INDEX IF EXISTS idx_audit_log_action_trgm');
    }
}
