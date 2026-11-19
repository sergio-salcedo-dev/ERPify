<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * The erasure of a document: the object-deletion sweep's progress marker and the ledger override flag.
 */
final class Version20261117201645 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Documents erasure: the sweep marker on document and the erasure ledger override table.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE erasure_ledger_override (set_at TIMESTAMP(6) WITH TIME ZONE NOT NULL, cleared_at TIMESTAMP(6) WITH TIME ZONE DEFAULT NULL, id UUID NOT NULL, reason VARCHAR(32) NOT NULL, record_ref UUID NOT NULL, PRIMARY KEY (id))');
        $this->addSql('ALTER TABLE document ADD object_deleted_at TIMESTAMP(6) WITH TIME ZONE DEFAULT NULL');
        $this->addSql('CREATE INDEX idx_document_erased_object_pending ON document (id) WHERE (((state)::text = \'erased\'::text) AND (object_deleted_at IS NULL))');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE erasure_ledger_override');
        $this->addSql('DROP INDEX idx_document_erased_object_pending');
        $this->addSql('ALTER TABLE document DROP object_deleted_at');
    }
}
