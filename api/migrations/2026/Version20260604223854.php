<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260604223854 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Drop media.deleted_at — media is hard-deleted (GDPR erasure); the soft-delete path was never used';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE media DROP deleted_at');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE media ADD deleted_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL');
    }
}
