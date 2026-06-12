<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260612221000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add unique index on media.content_hash backing the MediaRegistrar dedup check '
            . '(fails if duplicate hashes already exist — dedupe rows manually first)';
    }

    // CREATE/DROP INDEX CONCURRENTLY cannot run inside a transaction block.
    public function isTransactional(): bool
    {
        return false;
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE UNIQUE INDEX CONCURRENTLY IF NOT EXISTS media_content_hash_uniq ON media (content_hash)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP INDEX CONCURRENTLY IF EXISTS media_content_hash_uniq');
    }
}
