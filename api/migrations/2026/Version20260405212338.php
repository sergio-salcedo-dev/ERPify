<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260405212338 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add media table and bank logo';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE media (id UUID NOT NULL, content_hash VARCHAR(64) NOT NULL, mime_type VARCHAR(64) NOT NULL, byte_size INT NOT NULL, raw_bytes BYTEA NOT NULL, deleted_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL, created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, updated_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, PRIMARY KEY (id))');
        $this->addSql('ALTER TABLE bank ADD logo_media_id UUID DEFAULT NULL');
        $this->addSql('ALTER TABLE bank ADD CONSTRAINT FK_D860BF7ABAAE86A3 FOREIGN KEY (logo_media_id) REFERENCES media (id) NOT DEFERRABLE');
        $this->addSql('CREATE INDEX IDX_D860BF7ABAAE86A3 ON bank (logo_media_id)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE media');
        $this->addSql('ALTER TABLE bank DROP CONSTRAINT FK_D860BF7ABAAE86A3');
        $this->addSql('DROP INDEX IDX_D860BF7ABAAE86A3');
        $this->addSql('ALTER TABLE bank DROP logo_media_id');
    }
}
