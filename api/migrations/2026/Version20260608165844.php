<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260608165844 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Index bank.created_at and bank.updated_at to back the temporal range filters (story 1.7)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE INDEX idx_bank_created_at ON bank (created_at)');
        $this->addSql('CREATE INDEX idx_bank_updated_at ON bank (updated_at)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP INDEX idx_bank_created_at');
        $this->addSql('DROP INDEX idx_bank_updated_at');
    }
}
