<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260405212338 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add media table';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(
            'CREATE TABLE media ('
            . 'id UUID NOT NULL, '
            . 'content_hash VARCHAR(64) NOT NULL, '
            . 'mime_type VARCHAR(64) NOT NULL, '
            . 'byte_size INT NOT NULL, '
            . 'raw_bytes BYTEA NOT NULL, '
            . 'created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, '
            . 'updated_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, '
            . 'PRIMARY KEY (id))',
        );
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE media');
    }
}
