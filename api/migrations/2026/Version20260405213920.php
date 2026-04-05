<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260405213920 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add stored_object to Bank';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE bank ADD stored_object_key VARCHAR(512) DEFAULT NULL');
        $this->addSql('ALTER TABLE bank ADD stored_object_mime_type VARCHAR(64) DEFAULT NULL');
        $this->addSql('ALTER TABLE bank ADD stored_object_byte_size INT DEFAULT NULL');
        $this->addSql('ALTER TABLE bank ADD stored_object_content_hash VARCHAR(64) DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE bank DROP stored_object_key');
        $this->addSql('ALTER TABLE bank DROP stored_object_mime_type');
        $this->addSql('ALTER TABLE bank DROP stored_object_byte_size');
        $this->addSql('ALTER TABLE bank DROP stored_object_content_hash');
    }
}
