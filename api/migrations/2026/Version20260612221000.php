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

    // Plain (non-CONCURRENTLY) on purpose: the migrate pipeline runs --all-or-nothing and the
    // container entrypoint migrates on boot, both of which reject non-transactional migrations.
    // The brief table lock is acceptable — media rows are few and writes are rare.
    public function up(Schema $schema): void
    {
        $this->addSql('CREATE UNIQUE INDEX IF NOT EXISTS media_content_hash_uniq ON media (content_hash)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP INDEX IF EXISTS media_content_hash_uniq');
    }
}
