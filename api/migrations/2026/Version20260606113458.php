<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260606113458 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add unique index on domain_event.event_id (dedupe pre-existing rows, keep the oldest per event_id)';
    }

    public function up(Schema $schema): void
    {
        // Dedupe before the unique index: keep the oldest row per event_id (smallest id — v7 ids are
        // time-ordered) so the index creation cannot fail on pre-existing double-appends.
        $this->addSql('DELETE FROM domain_event a USING domain_event b WHERE a.event_id = b.event_id AND a.id > b.id');
        $this->addSql('CREATE UNIQUE INDEX domain_event_event_id_uniq ON domain_event (event_id)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP INDEX domain_event_event_id_uniq');
    }
}
