<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260510120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Create bank table with case- and accent-insensitive uniqueness';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(
            'CREATE TABLE bank ('
            . 'id UUID NOT NULL, '
            . 'name VARCHAR(255) NOT NULL, '
            . 'name_normalized VARCHAR(255) NOT NULL, '
            . 'short_name VARCHAR(50) NOT NULL, '
            . 'logo_media_id UUID DEFAULT NULL, '
            . 'stored_object_key VARCHAR(512) DEFAULT NULL, '
            . 'stored_object_mime_type VARCHAR(64) DEFAULT NULL, '
            . 'stored_object_byte_size INT DEFAULT NULL, '
            . 'stored_object_content_hash VARCHAR(64) DEFAULT NULL, '
            . 'created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, '
            . 'updated_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, '
            . 'PRIMARY KEY (id))',
        );

        $this->addSql('CREATE UNIQUE INDEX UNIQ_bank_name_normalized ON bank (name_normalized)');
        $this->addSql('CREATE UNIQUE INDEX UNIQ_bank_short_name ON bank (short_name)');
        $this->addSql('CREATE INDEX IDX_D860BF7ABAAE86A3 ON bank (logo_media_id)');

        $this->addSql(
            'ALTER TABLE bank ADD CONSTRAINT FK_D860BF7ABAAE86A3 '
            . 'FOREIGN KEY (logo_media_id) REFERENCES media (id) NOT DEFERRABLE',
        );
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE bank DROP CONSTRAINT FK_D860BF7ABAAE86A3');
        $this->addSql('DROP TABLE bank');
    }
}
