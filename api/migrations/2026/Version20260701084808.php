<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260701084808 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add audit_log.encryption_scope_id: the crypto-shredding scope a change row references when its diff holds encrypted PII.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE audit_log ADD COLUMN IF NOT EXISTS encryption_scope_id VARCHAR(160) DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE audit_log DROP COLUMN IF EXISTS encryption_scope_id');
    }
}
