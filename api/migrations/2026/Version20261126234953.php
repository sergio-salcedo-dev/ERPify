<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261126234953 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Read-side indexes: the audit timeline filtered by action and the document listing default order.';
    }

    public function up(Schema $schema): void
    {
        // Plain (blocking) builds, as the pipeline runs migrations transactionally: measured over 1.5M audit rows the
        // action index builds in 1.6 s and the document one over 200k rows in 0.1 s. A table far past those sizes wants
        // the build run out of band with CREATE INDEX CONCURRENTLY instead.
        $this->addSql('CREATE INDEX IF NOT EXISTS idx_document_created_at_id ON document (created_at, id)');
        $this->addSql('CREATE INDEX IF NOT EXISTS audit_log_action_idx ON audit_log (action, occurred_on, id)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP INDEX IF EXISTS audit_log_action_idx');
        $this->addSql('DROP INDEX IF EXISTS idx_document_created_at_id');
    }
}
