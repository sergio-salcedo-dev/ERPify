<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260701083342 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Create the dek_keystore table: one KEK-wrapped data-encryption key per encryption scope for audit crypto-shredding.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE dek_keystore (encryption_scope_id VARCHAR(160) NOT NULL, wrapped_dek TEXT DEFAULT NULL, kek_version SMALLINT NOT NULL, created_at TIMESTAMP(6) WITH TIME ZONE NOT NULL, destroyed_at TIMESTAMP(6) WITH TIME ZONE DEFAULT NULL, PRIMARY KEY (encryption_scope_id))');
        $this->addSql('CREATE INDEX dek_keystore_destroyed_idx ON dek_keystore (destroyed_at)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE IF EXISTS dek_keystore');
    }
}
