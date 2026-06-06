<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260606113458 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add unique index on domain_event.event_id (destructive: empties the table first — no production events exist yet)';
    }

    public function up(Schema $schema): void
    {
        // Explicit destructive migration: production has no domain events yet and dev/test audit rows
        // are disposable, so emptying the table replaces dedupe logic that would never see real data.
        $this->addSql('DELETE FROM domain_event');
        $this->addSql('CREATE UNIQUE INDEX domain_event_event_id_uniq ON domain_event (event_id)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP INDEX domain_event_event_id_uniq');
    }
}
