<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261119190500 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Documents versioning: the document_series mutex row and the series and predecessor foreign keys.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE document_series (id UUID NOT NULL, created_at TIMESTAMP(6) WITH TIME ZONE NOT NULL, closed_at TIMESTAMP(6) WITH TIME ZONE DEFAULT NULL, PRIMARY KEY (id))');
        // Every document filed before this migration is a first version, so each series is one document; the row is
        // stamped with the earliest creation of its series, which `document.created_at` keeps in UTC.
        $this->addSql("INSERT INTO document_series (id, created_at) SELECT series_id, MIN(created_at) AT TIME ZONE 'UTC' FROM document GROUP BY series_id");
        $this->addSql('CREATE INDEX IDX_D8698A765278319C ON document (series_id)');
        $this->addSql('CREATE INDEX IDX_D8698A7668C90015 ON document (predecessor_id)');
        $this->addSql('ALTER TABLE document ADD CONSTRAINT fk_document_series FOREIGN KEY (series_id) REFERENCES document_series (id)');
        $this->addSql('ALTER TABLE document ADD CONSTRAINT fk_document_predecessor FOREIGN KEY (predecessor_id) REFERENCES document (id)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE document DROP CONSTRAINT fk_document_predecessor');
        $this->addSql('ALTER TABLE document DROP CONSTRAINT fk_document_series');
        $this->addSql('DROP INDEX IDX_D8698A7668C90015');
        $this->addSql('DROP INDEX IDX_D8698A765278319C');
        $this->addSql('DROP TABLE document_series');
    }
}
